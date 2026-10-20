# EventEase

**Development of a RESTful Web API for an Event Ticketing and Venue Booking System**
SWC3633/SWC4443 Web API Development

| | |
|---|---|
| Student | AMZAR FAIZZUDDIN BIN MOHD HAMDAN |
| Student ID | AM2508020090 |
| Project type | Solo project (approved by lecturer) |
| Demonstration video | _add YouTube link_ |
| Project report | [docs/EventEase_Project_Report.pdf](docs/EventEase_Project_Report.pdf) |

EventEase is a PHP + MySQL REST API with an HTML/CSS/JavaScript frontend. Customers browse published events, book tickets and view or cancel their bookings; organisers manage their own events; administrators manage users, venues, events and bookings.

## Repository structure

```
backend/            PHP REST API
  index.php         entry point
  config.php        database settings and .env loader
  .htaccess         URL rewriting and Authorization header forwarding
  .env.example      template for JWT secret and API key
  src/Api.php       router, resources, validation and error handling
  src/Middleware.php JWT, API key, request logging, login rate limiting
frontend/           HTML pages and JavaScript (Fetch API) client
database/
  schema.sql        DDL - tables, keys and constraints
  seed.sql          DML - sample records
docs/
  EventEase_Project_Report.pdf
  ERD.png, ERD.md   entity relationship diagram
  API_DOCUMENTATION.md
  API_TEST_PLAN.md
  EventEase.postman_collection.json
```

## Requirements
- XAMPP with Apache, MySQL/MariaDB and PHP 8+; enable `pdo_mysql`, `curl` and Apache `mod_rewrite`.
- A browser and Postman.
- Internet access for the external QRServer API (QR tickets).

## Setup
1. Copy the `EventEase` folder to `C:\xampp\htdocs\EventEase\`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. In phpMyAdmin, import `database/schema.sql` and then `database/seed.sql`. This creates the `eventease` database with sample data.
4. Check the database settings in `backend/config.php` (default XAMPP: user `root`, blank password).
5. Copy `backend/.env.example` to `backend/.env` and set your own long random values for `EVENTEASE_JWT_SECRET` and `EVENTEASE_API_KEY`. `.env` is excluded from Git.
6. Open `http://localhost/EventEase/frontend/` in the browser. The API base URL is `http://localhost/EventEase/backend/api/v1`.
7. Import `docs/EventEase.postman_collection.json` into Postman and set the `apiKey` collection variable to your `EVENTEASE_API_KEY`.

## Demo accounts
All seeded accounts use the password `EventEaseDemo!2026` (classroom use only).

| Email | Role |
|---|---|
| admin@eventease.test | ADMIN |
| aina@eventease.test | ORGANISER |
| ravi@eventease.test | ORGANISER |
| sofia@eventease.test | CUSTOMER |
| daniel@eventease.test | CUSTOMER |

## Main endpoints
| Resource | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `GET /auth/me`, `POST /auth/logout` |
| Events | `GET/POST /events`, `GET/PUT/PATCH/DELETE /events/{id}` |
| Venues | `GET/POST /venues`, `GET/PUT/PATCH/DELETE /venues/{id}` |
| Users | `GET/POST /users`, `GET/PUT/PATCH/DELETE /users/{id}` (Admin) |
| Bookings | `GET/POST /bookings`, `GET /bookings/{id}`, `PATCH /bookings/{id}/cancel`, `PATCH /bookings/{id}/demo-confirmation`, `GET /bookings/{id}/qr` |
| Admin | `GET /admin/metrics` (Admin JWT + `X-API-Key`) |

Full details: `docs/API_DOCUMENTATION.md` and Section 3 of the project report.

## Security and API features
- HS256 JWT (8-hour expiry) with server-side token hashes so logout revokes the token.
- Role-based access control for Administrator, Organiser and Customer.
- API-key protection on the admin metrics endpoint.
- Request validation and consistent JSON error responses.
- Login rate limiting (10 attempts per 5 minutes per IP and email).
- Request logging to `backend/logs/api.log` (excluded from Git).
- Pagination, search, category filter and allow-listed sorting on `GET /events`.
- Booking creation inside a database transaction to prevent overselling.
- External QRServer API for QR tickets on confirmed bookings.

## Notes
- `demo-confirmation` simulates a payment for coursework only; no real payment gateway is used.
- A `PENDING` booking is not yet confirmed; QR tickets are issued only for `CONFIRMED` bookings.
- The `api_tokens` table is filled automatically when users log in, so `seed.sql` does not insert token rows.
