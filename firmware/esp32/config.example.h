#pragma once

// Copie para config.local.h e edite apenas a cópia, ignorada pelo Git.
constexpr char WIFI_SSID[] = "TESLA";
constexpr char WIFI_PASSWORD[] = "";  // Preencha localmente; nunca publique a senha.
constexpr char API_BASE_URL[] = "http://192.168.1.81:8080";  // Sem barra final.

// Configure explicitamente em config.local.h; token descartável exclusivo do laboratório.
#define SENSOR_ID "refrigerator"
#define API_WRITE_TOKEN ""  // Mesmo token de laboratório usado pelo servidor; nunca publique.

constexpr unsigned long MEASUREMENT_INTERVAL_MS = 10000;
constexpr unsigned long HEALTH_INTERVAL_MS = 30000;
constexpr unsigned long WIFI_RETRY_INTERVAL_MS = 15000;
constexpr uint16_t HTTP_TIMEOUT_MS = 1000;

// Copie estas opções para config.local.h para ativar o diagnóstico.
#define WIFI_DIAGNOSTICS_ENABLED 0
#define WIFI_SCAN_INTERVAL_MS 60000UL  // Mínimo efetivo: 60 segundos.
