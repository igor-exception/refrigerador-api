#include <OneWire.h>
#include <DallasTemperature.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <math.h>
#include <ctype.h>
#include <string.h>
#include <atomic>
#include <esp_wifi.h>

#if __has_include("config.local.h")
#include "config.local.h"
#else
#error "Copie config.example.h para config.local.h e configure a rede e a API."
#endif

// Configuração privada antiga continua compilável, mas não autoriza POST.
#ifndef SENSOR_ID
#define SENSOR_ID ""
#endif
#ifndef API_WRITE_TOKEN
#define API_WRITE_TOKEN ""
#endif
#if defined(CORE_DEBUG_LEVEL) && CORE_DEBUG_LEVEL > 0
#error "Selecione Core Debug Level: None para evitar logs internos de credenciais HTTP."
#endif

// Configurações locais anteriores continuam compiláveis sem estas opções.
#ifndef WIFI_DIAGNOSTICS_ENABLED
#define WIFI_DIAGNOSTICS_ENABLED 0
#endif
#ifndef WIFI_SCAN_INTERVAL_MS
#define WIFI_SCAN_INTERVAL_MS 60000UL
#endif

#define PINO_SENSOR 4

OneWire oneWire(PINO_SENSOR);
DallasTemperature sensor(&oneWire);

constexpr unsigned long CONVERSION_TIME_MS = 750;  // DS18B20, resolução de 12 bits.
unsigned long lastMeasurement = 0;
unsigned long conversionStarted = 0;
unsigned long lastWifiAttempt = 0;
unsigned long lastHealthCheck = 0;
bool conversionPending = false;
bool wifiWasConnected = false;
bool scanPending = false;
unsigned long lastScan = 0;
unsigned long scanStarted = 0;
int lastWifiStatus = -1;
std::atomic<unsigned int> pendingDisconnectReason{0};

const char* wifiStatusName(int status) {
  switch (status) {
    case WL_IDLE_STATUS: return "aguardando conexao";
    case WL_NO_SSID_AVAIL: return "SSID indisponivel";
    case WL_SCAN_COMPLETED: return "varredura concluida";
    case WL_CONNECTED: return "conectado";
    case WL_CONNECT_FAILED: return "falha de conexao";
    case WL_CONNECTION_LOST: return "conexao perdida";
    case WL_DISCONNECTED: return "desconectado";
    default: return "status desconhecido";
  }
}

const char* authName(wifi_auth_mode_t auth) {
  switch (auth) {
    case WIFI_AUTH_OPEN: return "aberta";
    case WIFI_AUTH_WEP: return "WEP";
    case WIFI_AUTH_WPA_PSK: return "WPA-PSK";
    case WIFI_AUTH_WPA2_PSK: return "WPA2-PSK";
    case WIFI_AUTH_WPA_WPA2_PSK: return "WPA/WPA2-PSK";
    case WIFI_AUTH_WPA2_ENTERPRISE: return "WPA2-Enterprise";
    case WIFI_AUTH_WPA3_PSK: return "WPA3-PSK";
    case WIFI_AUTH_WPA2_WPA3_PSK: return "WPA2/WPA3-PSK";
    default: return "outro (consulte o codigo do core ESP32)";
  }
}

const char* disconnectReasonName(unsigned int reason) {
  switch (reason) {
    case WIFI_REASON_AUTH_EXPIRE: return "autenticacao expirou";
    case WIFI_REASON_ASSOC_LEAVE: return "saida do ponto de acesso (pode ser local)";
    case WIFI_REASON_4WAY_HANDSHAKE_TIMEOUT: return "timeout do handshake de seguranca";
    case WIFI_REASON_BEACON_TIMEOUT: return "timeout de beacon do ponto de acesso";
    case WIFI_REASON_NO_AP_FOUND: return "ponto de acesso nao encontrado";
    case WIFI_REASON_AUTH_FAIL: return "falha de autenticacao";
    case WIFI_REASON_ASSOC_FAIL: return "falha de associacao";
    case WIFI_REASON_HANDSHAKE_TIMEOUT: return "timeout do handshake";
    default: return "outro motivo informado pelo ESP32";
  }
}

void onWifiEvent(WiFiEvent_t event, WiFiEventInfo_t info) {
  if (event == ARDUINO_EVENT_WIFI_STA_DISCONNECTED) {
    // Callback roda em outra tarefa: apenas publicar o motivo, sem Serial ou reconexão.
    pendingDisconnectReason.store(info.wifi_sta_disconnected.reason);
  }
}

void finishScan(int count) {
  if (count < 0) {
    Serial.println("Diagnostico: varredura falhou; isso nao prova ausencia da rede.");
  } else {
    bool found = false;
    int networks24GHz = 0;
    for (int i = 0; i < count; i++) {
      int channel = WiFi.channel(i);
      if (channel < 1 || channel > 14) continue;
      networks24GHz++;
      if (WiFi.SSID(i) != WIFI_SSID) continue;
      found = true;
      Serial.print("Rede configurada encontrada: ");
      Serial.println(WiFi.SSID(i));
      Serial.printf("RSSI: %ld dBm | canal: %d | BSSID: %s | autenticacao: %s (%d)\n",
        static_cast<long>(WiFi.RSSI(i)), channel, WiFi.BSSIDstr(i).c_str(),
        authName(WiFi.encryptionType(i)), static_cast<int>(WiFi.encryptionType(i)));
    }
    Serial.printf("Diagnostico: %d redes de 2,4 GHz visiveis.\n", networks24GHz);
    if (!found) {
      Serial.println("Rede configurada nao encontrada nesta varredura. SSID oculto pode nao ser identificado.");
    }
  }
  WiFi.scanDelete();
  scanPending = false;
  lastWifiAttempt = millis() - WIFI_RETRY_INTERVAL_MS;
}

void updateWifiDiagnostics(unsigned long now) {
  if (!WIFI_DIAGNOSTICS_ENABLED) return;
  int status = WiFi.status();
  if (status != lastWifiStatus) {
    Serial.printf("Diagnostico: status Wi-Fi %d (%s).\n", status, wifiStatusName(status));
    lastWifiStatus = status;
  }
  unsigned int reason = pendingDisconnectReason.exchange(0);
  if (reason != 0) {
    Serial.printf("Diagnostico: desconexao, motivo ESP32 %u (%s).\n", reason, disconnectReasonName(reason));
  }
  if (scanPending) {
    int result = WiFi.scanComplete();
    if (result != WIFI_SCAN_RUNNING) {
      finishScan(result);
    } else if (now - scanStarted >= 15000UL) {
      esp_wifi_scan_stop();
      finishScan(WIFI_SCAN_FAILED);
    }
    return;
  }
  const unsigned long interval = WIFI_SCAN_INTERVAL_MS < 60000UL ? 60000UL : WIFI_SCAN_INTERVAL_MS;
  // Não varrer uma conexão saudável. Reservar uma janela entre tentativas.
  if (status != WL_CONNECTED && now - lastScan >= interval
      && now - lastWifiAttempt >= WIFI_RETRY_INTERVAL_MS) {
    Serial.println("Diagnostico: pausando tentativa Wi-Fi para varredura assincrona; desconexao local pode gerar evento.");
    WiFi.disconnect();
    lastScan = now;
    scanStarted = now;
    int result = WiFi.scanNetworks(true, false, false, 120);
    scanPending = result == WIFI_SCAN_RUNNING;
    if (!scanPending) finishScan(result);
  }
}

void startWifi() {
  Serial.println("Wi-Fi: iniciando tentativa de conexao (sem esperar no loop).");
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  lastWifiAttempt = millis();
}

void updateWifi(unsigned long now) {
  bool connected = WiFi.status() == WL_CONNECTED;
  if (connected && !wifiWasConnected) {
    Serial.print("Wi-Fi conectado. IP: ");
    Serial.println(WiFi.localIP());
  } else if (!connected && wifiWasConnected) {
    Serial.println("Wi-Fi desconectado. Leituras continuam; reconexao sera tentada.");
  }
  wifiWasConnected = connected;

  if (!connected && !scanPending && now - lastWifiAttempt >= WIFI_RETRY_INTERVAL_MS) {
    Serial.println("Wi-Fi indisponivel. Confira rede e senha na configuracao local.");
    WiFi.disconnect();
    startWifi();
  }
}

void checkHealth() {
  WiFiClient client;
  HTTPClient http;
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.setReuse(false);

  if (http.begin(client, String(API_BASE_URL) + "/health")) {
    int status = http.GET();
    if (status > 0) {
      Serial.print("GET /health: HTTP ");
      Serial.println(status);
      Serial.println(status == 200 ? "API acessivel." : "API respondeu com status inesperado.");
    } else {
      Serial.print("GET /health falhou: ");
      Serial.println(HTTPClient::errorToString(status));
    }
  } else {
    Serial.println("GET /health: nao foi possivel iniciar a requisicao. Confira API_BASE_URL.");
  }
  http.end();
  client.stop();
}

bool postConfigurationValid() {
  if (strcmp(SENSOR_ID, "freezer") != 0 && strcmp(SENSOR_ID, "refrigerator") != 0) return false;
  if (strlen(API_WRITE_TOKEN) == 0) return false;
  for (const char* character = API_WRITE_TOKEN; *character != '\0'; character++) {
    if (isspace(static_cast<unsigned char>(*character))) return false;
  }
  return true;
}

void sendTemperature(float temperature) {
  // Defesa adicional: nenhum POST é iniciado com uma leitura inválida.
  if (!isfinite(temperature) || temperature == DEVICE_DISCONNECTED_C
      || temperature < -55.0f || temperature > 125.0f) return;
  if (!postConfigurationValid()) {
    Serial.println("POST desativado: configure SENSOR_ID e token de laboratorio em config.local.h.");
    return;
  }
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("POST nao enviado: Wi-Fi desconectado. Medicao nao armazenada para reenvio.");
    return;
  }

  WiFiClient client;
  HTTPClient http;
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.setReuse(false);
  http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
  if (http.begin(client, String(API_BASE_URL) + "/api/v1/temperaturas")) {
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Authorization", String("Bearer ") + API_WRITE_TOKEN);
    // SENSOR_ID foi validado; quatro casas preservam a resolução de 0,0625 °C.
    String body = String("{\"sensor_id\":\"") + SENSOR_ID
      + "\",\"temperature\":" + String(temperature, 4) + "}";
    int status = http.POST(body);
    Serial.print("POST /api/v1/temperaturas: ");
    if (status > 0) {
      Serial.print("HTTP ");
      Serial.println(status);
      if (status == 201) {
        Serial.println("API confirmou gravacao da medicao.");
      } else {
        Serial.println("Medicao sem confirmacao de gravacao. Confira status e configuracao local.");
      }
    } else {
      Serial.println(HTTPClient::errorToString(status));
      Serial.println("Resultado da gravacao desconhecido; esta medicao nao sera reenviada.");
    }
  } else {
    Serial.println("POST nao iniciado. Confira API_BASE_URL na configuracao local.");
  }
  // Não imprimir cabeçalhos, token ou corpo da resposta.
  http.end();
  client.stop();
}

void setup() {
  Serial.begin(115200);

  sensor.begin();
  sensor.setResolution(12);
  sensor.setWaitForConversion(false);

  Serial.println();
  Serial.println("Teste do sensor DS18B20");
  Serial.print("Sensores encontrados: ");
  Serial.println(sensor.getDeviceCount());
  if (!postConfigurationValid()) {
    Serial.println("POST desativado ate configurar SENSOR_ID e token de laboratorio locais.");
  }
  if (sensor.isParasitePowerMode()) {
    Serial.println("Aviso: conversao assincrona requer alimentacao externa do DS18B20 (VDD).");
  }

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(false);  // Tentativas controladas pelo intervalo abaixo.
  if (WIFI_DIAGNOSTICS_ENABLED) {
    WiFi.onEvent(onWifiEvent);
    Serial.println("Diagnostico Wi-Fi ativado. Varreduras somente enquanto desconectado.");
    const unsigned long interval = WIFI_SCAN_INTERVAL_MS < 60000UL ? 60000UL : WIFI_SCAN_INTERVAL_MS;
    lastScan = millis() - interval;  // Primeira varredura após a primeira tentativa.
  }
  startWifi();
  lastMeasurement = millis() - MEASUREMENT_INTERVAL_MS;
  lastHealthCheck = millis() - HEALTH_INTERVAL_MS;
}

void loop() {
  unsigned long now = millis();
  updateWifiDiagnostics(now);
  now = millis();
  updateWifi(now);

  if (!conversionPending && now - lastMeasurement >= MEASUREMENT_INTERVAL_MS) {
    sensor.requestTemperatures();
    lastMeasurement = millis();
    conversionStarted = lastMeasurement;
    conversionPending = true;
  }

  now = millis();
  if (conversionPending && now - conversionStarted >= CONVERSION_TIME_MS) {
    float temperatura = sensor.getTempCByIndex(0);
    conversionPending = false;
    if (temperatura == DEVICE_DISCONNECTED_C) {
      Serial.println("Sensor nao encontrado ou leitura falhou. Confira ligacao no GPIO 4.");
    } else if (!isfinite(temperatura) || temperatura < -55.0f || temperatura > 125.0f) {
      Serial.println("Leitura invalida: fora da faixa fisica do DS18B20.");
    } else {
      Serial.print("Temperatura: ");
      Serial.print(temperatura);
      Serial.println(" °C");
      sendTemperature(temperatura);
    }
  }

  // Não executar HTTP enquanto a conversão do sensor estiver pendente.
  if (!conversionPending && WiFi.status() == WL_CONNECTED
      && millis() - lastHealthCheck >= HEALTH_INTERVAL_MS) {
    checkHealth();
    lastHealthCheck = millis();
  }
}
