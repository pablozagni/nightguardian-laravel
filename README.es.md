# 🌙 NightGuardian para Laravel

SDK oficial de Laravel para [NightGuardian](https://github.com/pablozagni/nightguardian), monitoreo de
errores self-hosted y open source.

- **Laravel 5.8 a 13.** Un solo paquete, dos líneas (1.x y 2.x); Composer elige la correcta.
- **Sin dependencias** fuera de Laravel (usa ext-curl).
- **Nunca frena ni rompe tu app.** Envía después de responder, timeout de 2 s, corte automático y cola en
  disco si el colector no responde. No necesita queue worker ni cron.
- **Página de error amable** con código de referencia y un campo "¿qué estabas haciendo?".
- **Privado por defecto.** Contraseñas, tokens, cookies y cabeceras de autorización nunca salen del server.

## Instalación

```bash
composer require nightguardian/laravel
```

En `.env`:

```dotenv
NIGHTGUARDIAN_URL=https://nightguardian.tu-dominio.com
NIGHTGUARDIAN_TOKEN=          # se crea en el colector: Proyecto → Tokens
```

Probar:

```bash
php artisan nightguardian:test
```

Marcar un deploy desde tu script de actualización: `php artisan nightguardian:deploy`.

Ver [configuración](docs/configuration.md) y [cómo funciona](docs/design.md).
