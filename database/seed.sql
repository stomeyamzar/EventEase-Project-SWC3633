USE eventease;

-- Demo password for all seeded users: EventEaseDemo!2026
-- Hash generated using PHP password_hash() with PASSWORD_DEFAULT.
-- Replace these demo accounts/passwords before any public deployment.
INSERT INTO users (user_id, name, email, password_hash, role) VALUES
(1, 'System Administrator', 'admin@eventease.test', '$2y$12$SFEw/qk8Tg9BDvgnrTcDZO2zvyPbsys81Ynv.xHy.6O8ID2W9YURa', 'ADMIN'),
(2, 'Aina Organiser', 'aina@eventease.test', '$2y$12$SFEw/qk8Tg9BDvgnrTcDZO2zvyPbsys81Ynv.xHy.6O8ID2W9YURa', 'ORGANISER'),
(3, 'Ravi Organiser', 'ravi@eventease.test', '$2y$12$SFEw/qk8Tg9BDvgnrTcDZO2zvyPbsys81Ynv.xHy.6O8ID2W9YURa', 'ORGANISER'),
(4, 'Sofia Customer', 'sofia@eventease.test', '$2y$12$SFEw/qk8Tg9BDvgnrTcDZO2zvyPbsys81Ynv.xHy.6O8ID2W9YURa', 'CUSTOMER'),
(5, 'Daniel Customer', 'daniel@eventease.test', '$2y$12$SFEw/qk8Tg9BDvgnrTcDZO2zvyPbsys81Ynv.xHy.6O8ID2W9YURa', 'CUSTOMER');

INSERT INTO venues (venue_id, venue_name, address, capacity, status) VALUES
(1, 'KL Convention Studio', 'Kuala Lumpur', 500, 'ACTIVE'),
(2, 'Petaling Jaya Hall', 'Petaling Jaya, Selangor', 250, 'ACTIVE'),
(3, 'Shah Alam Creative Space', 'Shah Alam, Selangor', 150, 'ACTIVE'),
(4, 'Cyberjaya Auditorium', 'Cyberjaya, Selangor', 400, 'ACTIVE'),
(5, 'Kajang Community Centre', 'Kajang, Selangor', 100, 'ACTIVE');

INSERT INTO events
(event_id, organiser_id, venue_id, title, description, category, event_date, ticket_price, total_tickets, status) VALUES
(1, 2, 1, 'Digital Innovation Summit', 'A sample technology and innovation event.', 'Technology', '2027-03-10 09:00:00', 80.00, 300, 'PUBLISHED'),
(2, 2, 2, 'Creative Business Workshop', 'A practical workshop for small businesses.', 'Business', '2027-03-18 10:00:00', 35.00, 150, 'PUBLISHED'),
(3, 3, 3, 'Community Arts Festival', 'A community arts and culture programme.', 'Arts', '2027-04-02 11:00:00', 20.00, 120, 'PUBLISHED'),
(4, 3, 4, 'Future Skills Conference', 'A conference about future-ready skills.', 'Education', '2027-04-15 09:30:00', 60.00, 250, 'PUBLISHED'),
(5, 2, 5, 'Local Food and Craft Fair', 'A local food and craft community event.', 'Community', '2027-05-01 10:00:00', 10.00, 80, 'DRAFT');

INSERT INTO bookings
(booking_id, booking_reference, customer_id, event_id, quantity, total_amount, booking_status) VALUES
(1, 'EE-DEMO-1001', 4, 1, 2, 160.00, 'PENDING'),
(2, 'EE-DEMO-1002', 5, 2, 1, 35.00, 'CONFIRMED'),
(3, 'EE-DEMO-1003', 4, 3, 3, 60.00, 'CANCELLED'),
(4, 'EE-DEMO-1004', 5, 4, 2, 120.00, 'PENDING'),
(5, 'EE-DEMO-1005', 4, 2, 2, 70.00, 'CONFIRMED');

INSERT INTO payments
(payment_id, booking_id, amount, payment_method, payment_status, external_reference, paid_at) VALUES
(1, 1, 160.00, 'DEMO', 'PENDING', NULL, NULL),
(2, 2, 35.00, 'DEMO', 'SUCCESS', 'DEMO-PAY-2002', '2027-02-01 12:00:00'),
(3, 3, 60.00, 'DEMO', 'REFUNDED', 'DEMO-PAY-2003', '2027-02-02 12:00:00'),
(4, 4, 120.00, 'DEMO', 'PENDING', NULL, NULL),
(5, 5, 70.00, 'DEMO', 'SUCCESS', 'DEMO-PAY-2005', '2027-02-03 12:00:00');
