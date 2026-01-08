<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## Reports: Progress Report (initial)

You can generate a progress report per prospect (sales) in PDF, CSV (Excel-friendly) or Word (HTML) format.

- Endpoint (authenticated): GET /api/reports/progress
- Query params:
  - `format`: `pdf` | `excel` | `word` (default: `pdf`)
  - `range`: `daily` | `monthly` (default: `monthly`)
  - `date`: `YYYY-MM-DD` (required when `range=daily`)
  - `month`: `YYYY-MM` (required when `range=monthly`)
  - `user_id`: optional (filter by sales user)

Response/behavior:
- `format=excel` returns a CSV file (no additional package required)
- `format=word` returns an HTML file with `application/msword` header
- `format=pdf` uses `barryvdh/laravel-dompdf` if installed; otherwise it returns HTML as fallback

Installation for PDF support (recommended):

1. Install DOMPDF wrapper:

   composer require barryvdh/laravel-dompdf

2. (Optional) Publish config if you want to tweak PDF options:

   php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"

Examples:

- Monthly PDF (current month):
  GET /api/reports/progress?format=pdf&range=monthly

- Daily CSV for 2026-01-08:
  GET /api/reports/progress?format=excel&range=daily&date=2026-01-08

- Monthly Word for specific sales:
  GET /api/reports/progress?format=word&range=monthly&month=2026-01&user_id=5

Notes:
- Initial implementation summarizes per prospect: name, institution, position, assigned daily goals, approved missions in period, KPI percentage (period & overall), first and last submission dates, and per-daily-goal details (last submission status/date & reviewer note).
- For XLSX exports, consider installing `maatwebsite/excel` and adapting the controller to return an `Excel::download()` export class.

