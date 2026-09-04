# Salon ERP

Sistema de contabilidad, facturación, inventario y recursos humanos para el
estudio de maquillaje de Álvaro Rugama Make Up Studio.

## Descripción

Salon ERP es una aplicación web construida con Laravel 13 para centralizar la
operación diaria de un salón de belleza. Combina funcionalidades comerciales,
administrativas y financieras en una sola interfaz.

Módulos principales:

- Agenda de citas
- Punto de venta (POS) y caja
- Inventario y control de stock fraccionado
- Empleados y nómina con comisiones congeladas
- Asistencia diaria
- Adelantos y cuentas por cobrar
- Clientes y servicios
- Fórmulas y recetas de servicios
- Proveedores
- Mesa de cambio de divisas (NIO / USD)
- Contabilidad de partida doble automatizada
- Respaldos y restauración de la base de datos

## Stack tecnológico

- PHP 8.3
- Laravel 13
- Eloquent ORM
- Laravel Sanctum
- Blade + Tailwind CSS + Alpine.js + Vite
- MySQL / MariaDB

## Requisitos

- PHP >= 8.3
- Composer
- Node.js y npm
- MySQL / MariaDB

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

## Desarrollo

```bash
npm run dev
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0
```

## Pruebas

```bash
composer test
```

## Documentación

- `PROJECT_CONTEXT.md` — contexto funcional del proyecto
- `DOCUMENTACION_TECNICA.md` — documentación técnica de arquitectura