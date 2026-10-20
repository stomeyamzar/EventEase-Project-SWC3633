# EventEase REST API Documentation

Base URL: `http://localhost/EventEase/backend/api/v1`
Content type: `application/json`. Protected endpoints use `Authorization: Bearer <JWT>`. Admin metrics additionally require `X-API-Key`. IDs are integers. Error envelope: `{"success":false,"error":{"code":"...","message":"..."}}`.

## Authentication
| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| POST | `/auth/register` | Public | Create CUSTOMER account. Body: `name,email,password` (password >=10 chars). 201, 409 duplicate, 422 invalid. |
| POST | `/auth/login` | Public; rate limited | Validate credentials and return HS256 JWT. Body: `email,password`. 200, 401, 429. |
| GET | `/auth/me` | Bearer JWT | Return current account. 200, 401. |
| POST | `/auth/logout` | Bearer JWT | Revoke JWT in database and clear client session. 200, 401. |

## Resources
| Method | Endpoint | Auth / role | Purpose and key responses |
|---|---|---|---|
| GET | `/events?page=1&limit=10&q=tech&category=Technology&sort=event_date&direction=ASC` | Public; only PUBLISHED | Search/filter/sort/page events; includes `tickets_available`. 200. |
| GET | `/events/{id}` | Public for PUBLISHED; owner organiser/admin may read non-public event | Event details plus availability. 200, 404. |
| POST | `/events` | Organiser/Admin | Create event. Body: `venue_id,title,category,event_date,ticket_price,total_tickets`; optional `description,status`. 201, 401/403/422. |
| PUT/PATCH | `/events/{id}` | Organiser/Admin; organiser only own event | Update event. Body includes `venue_id,title,category,event_date,ticket_price,total_tickets,status`; 200, 403, 404, 422. |
| DELETE | `/events/{id}` | Admin | Delete if not referenced. 204, 409 FK conflict. |
| GET | `/venues` or `/venues/{id}` | Public | List/read venues. 200, 404. |
| POST | `/venues` | Admin | Create venue. Body: `venue_name,address,capacity`; optional `status`. 201, 422. |
| PUT/PATCH | `/venues/{id}` | Admin | Update venue. Body: `venue_name,address,capacity,status`. 200, 404, 422. |
| DELETE | `/venues/{id}` | Admin | Delete unreferenced venue. 204, 409 FK conflict. |
| GET | `/users` or `/users/{id}` | Admin | List/read users (password hashes excluded). 200, 401/403, 404. |
| POST | `/users` | Admin | Create account. Body: `name,email,password,role`. 201, 409/422. |
| PUT/PATCH | `/users/{id}` | Admin | Update name/email/role/is_active. 200, 404/422. |
| DELETE | `/users/{id}` | Admin; cannot delete self | Delete if unreferenced. 204, 409. |
| GET | `/bookings` or `/bookings/{id}` | Bearer; Customer own, Organiser event's bookings, Admin all | List/read bookings including payment status. 200, 401/403/404. |
| POST | `/bookings` | Customer | Body: `event_id,quantity` (1–10). Checks published event and available tickets. Creates PENDING booking and PENDING demo payment. 201, 409 insufficient tickets, 422 quantity. |
| PATCH | `/bookings/{id}/cancel` | Bearer; owner customer, event organiser or Admin | Cancel before event start; updates payment state. 200, 404, 409. |
| PATCH | `/bookings/{id}/demo-confirmation` | Admin | Explicit payment simulation only; not real payment. Marks booking CONFIRMED and payment SUCCESS. 200, 409. |
| GET | `/bookings/{id}/qr` | Bearer; owner customer, event organiser or Admin | Calls external QRServer QR Code API using cURL for confirmed ticket. 200 image base64, 409 not confirmed, 502 provider error, 503 cURL unavailable. |
| GET | `/admin/metrics` | Admin JWT + `X-API-Key` | Protected API-management metrics. 200, 401/403. |

## Sample booking request
```json
{"event_id": 1, "quantity": 1}
```

## Sample success response
```json
{"success":true,"message":"Booking created and awaiting payment confirmation.","data":{"booking_id":21,"booking_reference":"EE-EXAMPLE","quantity":1,"total_amount":80,"booking_status":"PENDING"}}
```
Use actual IDs/values from your local database when testing. Do not present this illustrative response as a captured test result.
