# 0.3.0 — 2026-05-09

- "Mit WisWes installieren" Admin-Modul mit Ein-Klick-Setup: erstellt
  automatisch einen WisWes-Tenant und speichert Widget-Token,
  Tenant-Slug und Tenant-Secret in `system_config`.
- PHP-seitige MCP-Endpunkte (`/wiswes/_mcp/*`) mit per-Tenant
  Bearer-Token-Schutz. Stellt 13 Tools für Warenkorb, Katalog,
  Checkout und Verkauf bereit, die das WisWes Chat-Backend aufruft.
- Storefront-Subscriber injiziert das Chat-Widget automatisch auf
  jeder Seite, sofern "Widget im Storefront anzeigen" aktiviert ist
  (Standard).
- Admin-Trennen-Button erhält den WisWes-Tenant, damit beim erneuten
  Verbinden keine Konversationsverläufe verloren gehen.

# 0.2.0 — 2026-04-22

- Erste öffentliche Veröffentlichung. Nur Storefront-Injektion über
  manuelle Twig-Anpassung; Admin-Modul als Platzhalter.

# 0.1.0 — 2026-04-15

- Interne Vorschau auf einem einzelnen Demo-Shop. Nicht veröffentlicht.
