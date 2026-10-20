# API Test Plan

Use Postman collection `EventEase.postman_collection.json`. Run tests against local XAMPP and record actual results; the included collection is a starting suite, not proof that tests have passed.

## Core scenarios
1. `GET /events?page=1&limit=10&sort=event_date&direction=ASC` — expect 200 JSON and `tickets_available` values.
2. `GET /events?q=Workshop` — expect search filtering.
3. `POST /auth/login` with a valid seeded account — expect 200 and JWT; invalid password should return 401.
4. `GET /bookings` without Authorization — expect 401.
5. `GET /bookings` with a customer JWT — should return only that customer's records.
6. `POST /bookings` with quantity 0 or 11 — expect 422.
7. `POST /bookings` for a published event with quantity within the limit — expect 201 and PENDING booking/payment.
8. Try booking more than `tickets_available` — expect 409 and no new booking row.
9. Public `GET /events/{id}` for DRAFT/CANCELLED event — expect 404 unless requester is the owner organiser or admin.
10. Organiser attempts to edit another organiser's event — expect 403.
11. Delete a venue/event referenced by another table — expect 409.
12. `GET /admin/metrics` with Admin JWT and valid `X-API-Key` — expect 200; missing/wrong API key should return 401.
13. Admin `PATCH /bookings/{id}/demo-confirmation` — expect 200 only for a non-cancelled booking; explicitly a simulation.
14. Confirmed booking `GET /bookings/{id}/qr` — verify external QRServer responds and JSON includes `image_base64`. If cURL/network is unavailable, record the actual 503/502 response.
15. Cancel booking before event starts; verify booking and payment statuses change. A booking for an event already started should return 409.

## Evidence format
For every test, record: test ID, date/time, method + URL, role/auth state, request body, actual HTTP status, short actual response, pass/fail, screenshot filename and notes. Do not replace actual response bodies with expected examples.
