<?php
declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';

final class Api {
    private static PDO $db;
    private static string $method;
    private static string $path;
    private static array $query;
    private static ?array $user = null;

    public static function run(): void {
        $started = microtime(true);
        register_shutdown_function(static function() use ($started): void {
            ApiMiddleware::logRequest(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', http_response_code() ?: 200, (microtime(true)-$started)*1000);
        });
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        try {
            self::$db = db();
            self::$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            self::$query = $_GET;
            $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            self::$path = '/' . trim(preg_replace('#^' . preg_quote($scriptDir, '#') . '#', '', $uriPath), '/');

           
            

            if (self::$path === '/api/v1/auth/register' && self::$method === 'POST') {
                self::register();
            }
            if (self::$path === '/api/v1/auth/login' && self::$method === 'POST') {
                self::login();
            }
            if (self::$path === '/api/v1/auth/me' && self::$method === 'GET') {
                self::$user = self::authenticate();
                self::json(200, ['success' => true, 'data' => self::$user]);
            }
            if (self::$path === '/api/v1/auth/logout' && self::$method === 'POST') {
                self::logout();
            }

            if (preg_match('#^/api/v1/bookings/(\d+)/qr$#', self::$path, $m) && self::$method === 'GET') {
                self::$user = self::authenticate();
                self::ticketQr((int)$m[1]);
            }
            if (preg_match('#^/api/v1/bookings/(\d+)/demo-confirmation$#', self::$path, $m) && self::$method === 'PATCH') {
                self::$user = self::authenticate(); self::requireRole(['ADMIN']); self::confirmDemoPayment((int)$m[1]);
            }
            if (self::$path === '/api/v1/admin/metrics' && self::$method === 'GET') {
                self::$user = self::authenticate(); self::requireRole(['ADMIN']); ApiMiddleware::requireApiKey();
                $counts = ['users'=>(int)self::$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),'events'=>(int)self::$db->query('SELECT COUNT(*) FROM events')->fetchColumn(),'bookings'=>(int)self::$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()];
                self::json(200, ['success'=>true,'data'=>$counts]);
            }

            // Public event reads; writes require organiser/admin.
            if (preg_match('#^/api/v1/events(?:/(\d+))?$#', self::$path, $m)) {
                $id = isset($m[1]) ? (int)$m[1] : null;
                self::events($id);
            }
            if (preg_match('#^/api/v1/venues(?:/(\d+))?$#', self::$path, $m)) {
                $id = isset($m[1]) ? (int)$m[1] : null;
                self::venues($id);
            }
            if (preg_match('#^/api/v1/users(?:/(\d+))?$#', self::$path, $m)) {
                $id = isset($m[1]) ? (int)$m[1] : null;
                self::users($id);
            }
            if (preg_match('#^/api/v1/bookings(?:/(\d+))?$#', self::$path, $m)) {
                $id = isset($m[1]) ? (int)$m[1] : null;
                self::bookings($id);
            }
            if (preg_match('#^/api/v1/bookings/(\d+)/cancel$#', self::$path, $m) && self::$method === 'PATCH') {
                self::$user = self::authenticate();
                self::cancelBooking((int)$m[1]);
            }

            self::json(404, ['success' => false, 'error' => [
                'code' => 'NOT_FOUND', 'message' => 'Endpoint not found.'
            ]]);
        } catch (PDOException $e) {
            error_log('[EventEase DB] ' . $e->getMessage());
            if ((string)$e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'foreign key constraint fails')) self::json(409, ['success'=>false,'error'=>['code'=>'RESOURCE_IN_USE','message'=>'This record is referenced by related records and cannot be deleted or changed.']]);
            self::json(500, ['success' => false, 'error' => ['code' => 'DATABASE_ERROR', 'message' => 'A database error occurred.']]);
        } catch (Throwable $e) {
            error_log('[EventEase API] ' . $e->getMessage());
            self::json(500, ['success' => false, 'error' => [
                'code' => 'INTERNAL_ERROR', 'message' => 'An unexpected server error occurred.'
            ]]);
        }
    }

    private static function json(int $status, array $body): never {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function body(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::json(400, ['success' => false, 'error' => [
                'code' => 'INVALID_JSON', 'message' => 'Request body must be a valid JSON object.'
            ]]);
        }
        return $data;
    }

    private static function requireFields(array $data, array $fields): void {
        $missing = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        if ($missing) {
            self::json(422, ['success' => false, 'error' => [
                'code' => 'VALIDATION_ERROR', 'message' => 'Required fields are missing.',
                'fields' => $missing
            ]]);
        }
    }

    private static function authenticate(): array {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) self::json(401, ['success'=>false,'error'=>['code'=>'AUTH_REQUIRED','message'=>'A bearer JWT is required.']]);
        $token = trim($matches[1]); $claims = ApiMiddleware::verifyJwt($token);
        if (!$claims) self::json(401, ['success'=>false,'error'=>['code'=>'INVALID_TOKEN','message'=>'JWT is invalid or expired.']]);
        $hash = hash('sha256', $token);
        $stmt = self::$db->prepare('SELECT u.user_id,u.name,u.email,u.role FROM api_tokens t JOIN users u ON u.user_id=t.user_id WHERE t.token_hash=? AND t.expires_at > NOW() AND u.is_active=1');
        $stmt->execute([$hash]); $user = $stmt->fetch();
        if (!$user || (int)$user['user_id'] !== (int)$claims['sub'] || $user['role'] !== ($claims['role'] ?? '')) self::json(401, ['success'=>false,'error'=>['code'=>'REVOKED_TOKEN','message'=>'JWT is revoked, invalid or expired. Please log in again.']]);
        return $user;
    }

    private static function requireRole(array $roles): void {
        self::$user = self::$user ?? self::authenticate();
        if (!in_array(self::$user['role'], $roles, true)) {
            self::json(403, ['success' => false, 'error' => [
                'code' => 'FORBIDDEN', 'message' => 'Your role cannot perform this action.'
            ]]);
        }
    }

    private static function register(): never {
        $d = self::body();
        self::requireFields($d, ['name', 'email', 'password']);
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            self::json(422, ['success' => false, 'error' => [
                'code' => 'INVALID_EMAIL', 'message' => 'Enter a valid email address.'
            ]]);
        }
        if (strlen((string)$d['password']) < 10) {
            self::json(422, ['success' => false, 'error' => [
                'code' => 'WEAK_PASSWORD', 'message' => 'Password must be at least 10 characters.'
            ]]);
        }
        $stmt = self::$db->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)');
        try {
            $stmt->execute([trim($d['name']), strtolower(trim($d['email'])),
                password_hash($d['password'], PASSWORD_DEFAULT), 'CUSTOMER']);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                self::json(409, ['success' => false, 'error' => [
                    'code' => 'EMAIL_EXISTS', 'message' => 'Email is already registered.'
                ]]);
            }
            throw $e;
        }
        self::json(201, ['success' => true, 'message' => 'Account created.',
            'data' => ['user_id' => (int)self::$db->lastInsertId()]]);
    }

    private static function logout(): never {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            self::json(401, ['success' => false, 'error' => [
                'code' => 'AUTH_REQUIRED', 'message' => 'A bearer token is required.'
            ]]);
        }
        $hash = hash('sha256', trim($matches[1]));
        $stmt = self::$db->prepare('DELETE FROM api_tokens WHERE token_hash=?');
        $stmt->execute([$hash]);
        self::json(200, ['success' => true, 'message' => 'Logged out successfully.']);
    }

    private static function login(): never {
        $d = self::body();
        self::requireFields($d, ['email', 'password']);
        if (!ApiMiddleware::allowLoginAttempt((string)$d['email'])) self::json(429, ['success'=>false,'error'=>['code'=>'RATE_LIMITED','message'=>'Too many login attempts. Wait five minutes and try again.']]);
        $stmt = self::$db->prepare('SELECT user_id,name,email,password_hash,role,is_active FROM users WHERE email=?');
        $stmt->execute([strtolower(trim($d['email']))]);
        $u = $stmt->fetch();
        if (!$u || !password_verify((string)$d['password'], $u['password_hash']) || !(int)$u['is_active']) {
            self::json(401, ['success' => false, 'error' => [
                'code' => 'LOGIN_FAILED', 'message' => 'Email or password is incorrect.'
            ]]);
        }
        $expiresAt = time() + TOKEN_TTL_HOURS * 3600;
        $jti = bin2hex(random_bytes(16));
        $token = ApiMiddleware::createJwt(['iss'=>'EventEase','sub'=>(int)$u['user_id'],'email'=>$u['email'],'role'=>$u['role'],'iat'=>time(),'exp'=>$expiresAt,'jti'=>$jti]);
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', $expiresAt);
        $stmt = self::$db->prepare('INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (?,?,?)');
        $stmt->execute([$u['user_id'], $hash, $expires]);
        unset($u['password_hash'], $u['is_active']);
        self::json(200, ['success' => true, 'message' => 'Login successful.',
            'data' => ['token_type' => 'Bearer', 'access_token' => $token,
                'expires_at' => $expires, 'user' => $u]]);
    }

    private static function events(?int $id): never {
        if (self::$method === 'GET') {
            if ($id) {
                $s = self::$db->prepare("SELECT e.*, v.venue_name, v.capacity, COALESCE((SELECT SUM(b.quantity) FROM bookings b WHERE b.event_id=e.event_id AND b.booking_status IN ('PENDING','CONFIRMED')),0) AS tickets_booked FROM events e JOIN venues v ON v.venue_id=e.venue_id WHERE e.event_id=?");
                $s->execute([$id]); $row = $s->fetch();
                if (!$row) self::notFound('Event');
                if ($row['status'] !== 'PUBLISHED') {
                    self::$user = self::authenticate();
                    if (self::$user['role'] === 'CUSTOMER' || (self::$user['role'] === 'ORGANISER' && (int)$row['organiser_id'] !== (int)self::$user['user_id'])) self::notFound('Published event');
                }
                $row['tickets_available'] = max(0, (int)$row['total_tickets'] - (int)$row['tickets_booked']);
                self::json(200, ['success' => true, 'data' => $row]);
            }
            $page = max(1, (int)(self::$query['page'] ?? 1));
            $limit = min(50, max(1, (int)(self::$query['limit'] ?? 10)));
            $offset = ($page - 1) * $limit;
            $manage = (string)(self::$query['manage'] ?? '') === '1';
            $where = ["e.status='PUBLISHED'"];
            $params = [];
            if ($manage) {
                self::$user = self::authenticate();
                if (!in_array(self::$user['role'], ['ADMIN','ORGANISER'], true)) self::json(403,['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Only Admin or Organiser can manage events.']]);
                $where = [];
                if (self::$user['role'] === 'ORGANISER') { $where[]='e.organiser_id=?'; $params[]=(int)self::$user['user_id']; }
            }
            if (!empty(self::$query['q'])) {
                $where[] = '(e.title LIKE ? OR e.description LIKE ? OR e.category LIKE ?)';
                $q = '%' . self::$query['q'] . '%';
                array_push($params, $q, $q, $q);
            }
            if (!empty(self::$query['category'])) {
                $where[] = 'e.category = ?'; $params[] = self::$query['category'];
            }
            $sortAllow = ['event_date'=>'e.event_date','title'=>'e.title','ticket_price'=>'e.ticket_price'];
            $sortKey = (string)(self::$query['sort'] ?? 'event_date'); $sortColumn = $sortAllow[$sortKey] ?? 'e.event_date';
            $sortDir = strtoupper((string)(self::$query['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
            $whereSql = $where ? implode(' AND ', $where) : '1=1';
            $sql = "SELECT e.event_id,e.title,e.description,e.category,e.event_date,e.ticket_price,e.total_tickets,e.status,v.venue_name,v.capacity,
                    GREATEST(0,e.total_tickets-COALESCE((SELECT SUM(b.quantity) FROM bookings b WHERE b.event_id=e.event_id AND b.booking_status IN ('PENDING','CONFIRMED')),0)) AS tickets_available
                    FROM events e JOIN venues v ON v.venue_id=e.venue_id WHERE " . $whereSql .
                    ' ORDER BY ' . $sortColumn . ' ' . $sortDir . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
            $s = self::$db->prepare($sql); $s->execute($params);
            self::json(200, ['success' => true, 'data' => $s->fetchAll(),
                'meta' => ['page' => $page, 'limit' => $limit]]);
        }
        self::requireRole(['ADMIN','ORGANISER']);
        if (self::$method === 'POST' && !$id) {
            $d = self::body();
            self::requireFields($d, ['venue_id','title','category','event_date','ticket_price','total_tickets']);
            if (!is_numeric($d['ticket_price']) || (float)$d['ticket_price'] < 0 ||
                filter_var($d['total_tickets'], FILTER_VALIDATE_INT) === false || (int)$d['total_tickets'] < 1) {
                self::json(422, ['success' => false, 'error' => ['code'=>'INVALID_EVENT_DATA','message'=>'Price must be non-negative and ticket count must be a positive integer.']]);
            }
            $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', str_replace(' ', 'T', (string)$d['event_date'])) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$d['event_date']);
            if (!$date || $date < new DateTimeImmutable('now')) self::json(422,['success'=>false,'error'=>['code'=>'INVALID_EVENT_DATE','message'=>'Event date must be a valid future date/time.']]);
            $venueCheck = self::$db->prepare("SELECT capacity,status FROM venues WHERE venue_id=?"); $venueCheck->execute([(int)$d['venue_id']]); $venue = $venueCheck->fetch();
            if (!$venue || $venue['status'] !== 'ACTIVE') self::json(422,['success'=>false,'error'=>['code'=>'INVALID_VENUE','message'=>'Choose an existing active venue.']]);
            if ((int)$d['total_tickets'] > (int)$venue['capacity']) self::json(422,['success'=>false,'error'=>['code'=>'CAPACITY_EXCEEDED','message'=>'Total tickets cannot exceed venue capacity.']]);
            $s = self::$db->prepare('INSERT INTO events (organiser_id,venue_id,title,description,category,event_date,ticket_price,total_tickets,status) VALUES (?,?,?,?,?,?,?,?,?)');
            $s->execute([self::$user['user_id'], (int)$d['venue_id'], trim($d['title']), $d['description'] ?? null,
                trim($d['category']), $d['event_date'], (float)$d['ticket_price'], (int)$d['total_tickets'],
                $d['status'] ?? 'DRAFT']);
            self::json(201, ['success'=>true,'message'=>'Event created.','data'=>['event_id'=>(int)self::$db->lastInsertId()]]);
        }
        if ($id && in_array(self::$method, ['PUT','PATCH'], true)) {
            $d = self::body(); self::requireFields($d, ['venue_id','title','category','event_date','ticket_price','total_tickets','status']);
            if (!in_array($d['status'], ['DRAFT','PUBLISHED','CANCELLED','COMPLETED'], true) || !is_numeric($d['ticket_price']) || (float)$d['ticket_price'] < 0 || filter_var($d['total_tickets'], FILTER_VALIDATE_INT) === false || (int)$d['total_tickets'] < 1) self::json(422,['success'=>false,'error'=>['code'=>'INVALID_EVENT_DATA','message'=>'Check status, price and ticket quantity.']]);
            if (self::$user['role'] === 'ORGANISER') { $owner=self::$db->prepare('SELECT organiser_id FROM events WHERE event_id=?'); $owner->execute([$id]); $ownerId=$owner->fetchColumn(); if (!$ownerId) self::notFound('Event'); if ((int)$ownerId !== (int)self::$user['user_id']) self::json(403,['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Organisers may only edit their own events.']]); }
            $venueCheck=self::$db->prepare('SELECT capacity,status FROM venues WHERE venue_id=?'); $venueCheck->execute([(int)$d['venue_id']]); $venue=$venueCheck->fetch(); if (!$venue || $venue['status']!=='ACTIVE') self::json(422,['success'=>false,'error'=>['code'=>'INVALID_VENUE','message'=>'Choose an existing active venue.']]); if ((int)$d['total_tickets']>(int)$venue['capacity']) self::json(422,['success'=>false,'error'=>['code'=>'CAPACITY_EXCEEDED','message'=>'Total tickets cannot exceed venue capacity.']]);
            $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', str_replace(' ', 'T', (string)$d['event_date'])) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$d['event_date']); if (!$date || $date < new DateTimeImmutable('now')) self::json(422,['success'=>false,'error'=>['code'=>'INVALID_EVENT_DATE','message'=>'Enter a valid future date and time.']]);
            $s = self::$db->prepare('UPDATE events SET venue_id=?,title=?,description=?,category=?,event_date=?,ticket_price=?,total_tickets=?,status=? WHERE event_id=?');
            $s->execute([(int)$d['venue_id'],trim($d['title']),$d['description']??null,trim($d['category']),$d['event_date'],(float)$d['ticket_price'],(int)$d['total_tickets'],$d['status'], $id]);
            if (!$s->rowCount()) { $exists=self::$db->prepare('SELECT 1 FROM events WHERE event_id=?'); $exists->execute([$id]); if (!$exists->fetchColumn()) self::notFound('Event'); }
            self::json(200,['success'=>true,'message'=>'Event updated.']);
        }
        if ($id && self::$method === 'DELETE') {
            self::requireRole(['ADMIN']);
            $s = self::$db->prepare('DELETE FROM events WHERE event_id=?'); $s->execute([$id]);
            if (!$s->rowCount()) self::notFound('Event');
            http_response_code(204); exit;
        }
        self::methodNotAllowed();
    }

    private static function venues(?int $id): never {
        if (self::$method === 'GET') {
            if ($id) {
                $s=self::$db->prepare('SELECT * FROM venues WHERE venue_id=?'); $s->execute([$id]); $r=$s->fetch();
                if (!$r) self::notFound('Venue');
                self::json(200,['success'=>true,'data'=>$r]);
            }
            $s=self::$db->query('SELECT * FROM venues ORDER BY venue_name');
            self::json(200,['success'=>true,'data'=>$s->fetchAll()]);
        }
        self::requireRole(['ADMIN']);
        if (self::$method === 'POST' && !$id) {
            $d=self::body(); self::requireFields($d,['venue_name','address','capacity']);
            if (filter_var($d['capacity'],FILTER_VALIDATE_INT)===false || (int)$d['capacity']<1) {
                self::json(422,['success'=>false,'error'=>['code'=>'INVALID_CAPACITY','message'=>'Capacity must be a positive integer.']]);
            }
            $status=$d['status']??'ACTIVE'; if (!in_array($status,['ACTIVE','INACTIVE'],true)) self::json(422,['success'=>false,'error'=>['code'=>'INVALID_VENUE_STATUS','message'=>'Status must be ACTIVE or INACTIVE.']]);
            $s=self::$db->prepare('INSERT INTO venues (venue_name,address,capacity,status) VALUES (?,?,?,?)');
            $s->execute([trim($d['venue_name']),trim($d['address']),(int)$d['capacity'],$status]);
            self::json(201,['success'=>true,'message'=>'Venue created.','data'=>['venue_id'=>(int)self::$db->lastInsertId()]]);
        }
        if ($id && in_array(self::$method,['PUT','PATCH'],true)) {
            $d=self::body(); self::requireFields($d,['venue_name','address','capacity','status']);
            if (!in_array($d['status'],['ACTIVE','INACTIVE'],true) || filter_var($d['capacity'],FILTER_VALIDATE_INT)===false || (int)$d['capacity']<1) self::json(422,['success'=>false,'error'=>['code'=>'INVALID_VENUE_DATA','message'=>'Check venue status and positive capacity.']]);
            $s=self::$db->prepare('UPDATE venues SET venue_name=?,address=?,capacity=?,status=? WHERE venue_id=?');
            $s->execute([trim($d['venue_name']),trim($d['address']),(int)$d['capacity'],$d['status'],$id]);
            if (!$s->rowCount()) { $exists=self::$db->prepare('SELECT 1 FROM venues WHERE venue_id=?'); $exists->execute([$id]); if (!$exists->fetchColumn()) self::notFound('Venue'); }
            self::json(200,['success'=>true,'message'=>'Venue updated.']);
        }
        if ($id && self::$method==='DELETE') {
            self::requireRole(['ADMIN']);
            $s=self::$db->prepare('DELETE FROM venues WHERE venue_id=?'); $s->execute([$id]);
            if (!$s->rowCount()) self::notFound('Venue');
            http_response_code(204); exit;
        }
        self::methodNotAllowed();
    }

    private static function users(?int $id): never {
        self::requireRole(['ADMIN']);
        if (self::$method==='GET') {
            if ($id) {
                $s=self::$db->prepare('SELECT user_id,name,email,role,is_active,created_at FROM users WHERE user_id=?');
                $s->execute([$id]); $r=$s->fetch(); if (!$r) self::notFound('User');
                self::json(200,['success'=>true,'data'=>$r]);
            }
            $s=self::$db->query('SELECT user_id,name,email,role,is_active,created_at FROM users ORDER BY user_id');
            self::json(200,['success'=>true,'data'=>$s->fetchAll()]);
        }
        if (self::$method==='POST' && !$id) {
            $d=self::body(); self::requireFields($d,['name','email','password','role']);
            if (!filter_var($d['email'],FILTER_VALIDATE_EMAIL) || !in_array($d['role'],['ADMIN','ORGANISER','CUSTOMER'],true) || strlen($d['password'])<10) {
                self::json(422,['success'=>false,'error'=>['code'=>'INVALID_USER_DATA','message'=>'Check email, role and password length (minimum 10).']]);
            }
            $s=self::$db->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)');
            $s->execute([trim($d['name']),strtolower(trim($d['email'])),password_hash($d['password'],PASSWORD_DEFAULT),$d['role']]);
            self::json(201,['success'=>true,'message'=>'User created.','data'=>['user_id'=>(int)self::$db->lastInsertId()]]);
        }
        if ($id && in_array(self::$method,['PUT','PATCH'],true)) {
            $d=self::body(); self::requireFields($d,['name','email','role','is_active']);
            if (!filter_var($d['email'],FILTER_VALIDATE_EMAIL) || !in_array($d['role'],['ADMIN','ORGANISER','CUSTOMER'],true)) {
                self::json(422,['success'=>false,'error'=>['code'=>'INVALID_USER_DATA','message'=>'Invalid email or role.']]);
            }
            $s=self::$db->prepare('UPDATE users SET name=?,email=?,role=?,is_active=? WHERE user_id=?');
            $s->execute([trim($d['name']),strtolower(trim($d['email'])),$d['role'],(int)(bool)$d['is_active'],$id]);
            if (!$s->rowCount()) { $exists=self::$db->prepare('SELECT 1 FROM users WHERE user_id=?'); $exists->execute([$id]); if (!$exists->fetchColumn()) self::notFound('User'); }
            self::json(200,['success'=>true,'message'=>'User updated.']);
        }
        if ($id && self::$method==='DELETE') {
            if ((int)$id === (int)self::$user['user_id']) self::json(409,['success'=>false,'error'=>['code'=>'SELF_DELETE_BLOCKED','message'=>'You cannot delete your own active administrator account.']]);
            $s=self::$db->prepare('DELETE FROM users WHERE user_id=?'); $s->execute([$id]);
            if (!$s->rowCount()) self::notFound('User');
            http_response_code(204); exit;
        }
        self::methodNotAllowed();
    }

    private static function bookings(?int $id): never {
        self::$user = self::authenticate();
        if (self::$method==='GET') {
            if ($id) {
                $s=self::$db->prepare('SELECT b.*,e.title,e.event_date,p.payment_status,p.payment_method,p.external_reference FROM bookings b JOIN events e ON e.event_id=b.event_id LEFT JOIN payments p ON p.booking_id=b.booking_id WHERE b.booking_id=?');
                $s->execute([$id]); $r=$s->fetch(); if (!$r) self::notFound('Booking');
                if (self::$user['role']==='CUSTOMER' && (int)$r['customer_id']!==(int)self::$user['user_id']) self::notFound('Booking');
                if (self::$user['role']==='ORGANISER') {
                    $check=self::$db->prepare('SELECT 1 FROM events WHERE event_id=? AND organiser_id=?');
                    $check->execute([$r['event_id'],self::$user['user_id']]);
                    if (!$check->fetchColumn()) self::notFound('Booking');
                }
                self::json(200,['success'=>true,'data'=>$r]);
            }
            $sql='SELECT b.*,e.title,e.event_date,p.payment_status,p.payment_method,p.external_reference FROM bookings b JOIN events e ON e.event_id=b.event_id LEFT JOIN payments p ON p.booking_id=b.booking_id';
            $params=[];
            if (self::$user['role']==='CUSTOMER') { $sql.=' WHERE b.customer_id=?'; $params[] = self::$user['user_id']; }
            elseif (self::$user['role']==='ORGANISER') { $sql.=' WHERE e.organiser_id=?'; $params[] = self::$user['user_id']; }
            $sql.=' ORDER BY b.created_at DESC';
            $s=self::$db->prepare($sql); $s->execute($params);
            self::json(200,['success'=>true,'data'=>$s->fetchAll()]);
        }
        if (self::$method==='POST' && !$id) {
            if (self::$user['role']!=='CUSTOMER') self::json(403,['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Only customers can create bookings.']]);
            $d=self::body(); self::requireFields($d,['event_id','quantity']);
            $qty=filter_var($d['quantity'],FILTER_VALIDATE_INT);
            if ($qty===false || $qty<1 || $qty>10) self::json(422,['success'=>false,'error'=>['code'=>'INVALID_QUANTITY','message'=>'Quantity must be an integer from 1 to 10.']]);
            self::$db->beginTransaction();
            try {
                $s=self::$db->prepare("SELECT * FROM events WHERE event_id=? AND status='PUBLISHED' FOR UPDATE");
                $s->execute([(int)$d['event_id']]); $event=$s->fetch();
                if (!$event) { self::$db->rollBack(); self::notFound('Published event'); }
                $s=self::$db->prepare("SELECT COALESCE(SUM(quantity),0) FROM bookings WHERE event_id=? AND booking_status IN ('PENDING','CONFIRMED')");
                $s->execute([$event['event_id']]); $booked=(int)$s->fetchColumn();
                if ($booked+$qty>(int)$event['total_tickets']) {
                    self::$db->rollBack();
                    self::json(409,['success'=>false,'error'=>['code'=>'INSUFFICIENT_TICKETS','message'=>'Not enough tickets remain.']]);
                }
                $ref='EE-' . strtoupper(bin2hex(random_bytes(5)));
                $amount=round((float)$event['ticket_price']*$qty,2);
                $s=self::$db->prepare("INSERT INTO bookings (booking_reference,customer_id,event_id,quantity,total_amount,booking_status) VALUES (?,?,?,?,?,'PENDING')");
                $s->execute([$ref,self::$user['user_id'],$event['event_id'],$qty,$amount]);
                $bid=(int)self::$db->lastInsertId();
                $s=self::$db->prepare("INSERT INTO payments (booking_id,amount,payment_method,payment_status) VALUES (?,?,'DEMO','PENDING')");
                $s->execute([$bid,$amount]);
                self::$db->commit();
                self::json(201,['success'=>true,'message'=>'Booking created and awaiting payment confirmation.',
                    'data'=>['booking_id'=>$bid,'booking_reference'=>$ref,'quantity'=>$qty,'total_amount'=>$amount,'booking_status'=>'PENDING']]);
            } catch (Throwable $e) {
                if (self::$db->inTransaction()) self::$db->rollBack();
                throw $e;
            }
        }
        self::methodNotAllowed();
    }

    private static function cancelBooking(int $id): never {
        $s=self::$db->prepare('SELECT * FROM bookings WHERE booking_id=?'); $s->execute([$id]); $b=$s->fetch();
        if (!$b) self::notFound('Booking');
        if (self::$user['role']==='CUSTOMER' && (int)$b['customer_id']!==(int)self::$user['user_id']) self::notFound('Booking');
        if (!in_array(self::$user['role'],['ADMIN','ORGANISER','CUSTOMER'],true)) self::json(403,['success'=>false,'error'=>['code'=>'FORBIDDEN','message'=>'Not allowed.']]);
        if (self::$user['role']==='ORGANISER') { $owner=self::$db->prepare('SELECT organiser_id FROM events WHERE event_id=?'); $owner->execute([$b['event_id']]); if ((int)$owner->fetchColumn() !== (int)self::$user['user_id']) self::json(404,['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Booking not found.']]); }
        $eventDate=self::$db->prepare('SELECT event_date FROM events WHERE event_id=?'); $eventDate->execute([$b['event_id']]); if (new DateTimeImmutable() >= new DateTimeImmutable((string)$eventDate->fetchColumn())) self::json(409,['success'=>false,'error'=>['code'=>'CANCELLATION_CLOSED','message'=>'Bookings cannot be cancelled after the event has started.']]);
        if ($b['booking_status']==='CANCELLED') self::json(409,['success'=>false,'error'=>['code'=>'ALREADY_CANCELLED','message'=>'Booking is already cancelled.']]);
        self::$db->beginTransaction();
        $s=self::$db->prepare("UPDATE bookings SET booking_status='CANCELLED' WHERE booking_id=?"); $s->execute([$id]);
        $p=self::$db->prepare("UPDATE payments SET payment_status=CASE WHEN payment_status='SUCCESS' THEN 'REFUNDED' ELSE 'FAILED' END WHERE booking_id=? AND payment_status IN ('PENDING','SUCCESS')"); $p->execute([$id]);
        self::$db->commit();
        self::json(200,['success'=>true,'message'=>'Booking cancelled.']);
    }

    private static function confirmDemoPayment(int $id): never {
        $s=self::$db->prepare('SELECT booking_id,booking_status FROM bookings WHERE booking_id=?'); $s->execute([$id]); $b=$s->fetch(); if (!$b) self::notFound('Booking');
        if ($b['booking_status'] === 'CANCELLED') self::json(409,['success'=>false,'error'=>['code'=>'BOOKING_CANCELLED','message'=>'A cancelled booking cannot be confirmed.']]);
        self::$db->beginTransaction();
        $u=self::$db->prepare("UPDATE bookings SET booking_status='CONFIRMED' WHERE booking_id=?"); $u->execute([$id]);
        $p=self::$db->prepare("UPDATE payments SET payment_status='SUCCESS', paid_at=NOW(), external_reference=COALESCE(external_reference,?) WHERE booking_id=?"); $p->execute(['DEMO-'.bin2hex(random_bytes(4)),$id]);
        self::$db->commit();
        self::json(200,['success'=>true,'message'=>'Demo payment marked successful. This is a simulation; no real payment was processed.','data'=>['booking_id'=>$id,'booking_status'=>'CONFIRMED','payment_status'=>'SUCCESS']]);
    }

    private static function ticketQr(int $id): never {
        $s=self::$db->prepare('SELECT b.booking_id,b.booking_reference,b.customer_id,b.event_id,b.quantity,b.booking_status,e.title,e.event_date FROM bookings b JOIN events e ON e.event_id=b.event_id WHERE b.booking_id=?');
        $s->execute([$id]); $b=$s->fetch(); if (!$b) self::notFound('Booking');
        if (self::$user['role']==='CUSTOMER' && (int)$b['customer_id'] !== (int)self::$user['user_id']) self::notFound('Booking');
        if (self::$user['role']==='ORGANISER') { $o=self::$db->prepare('SELECT organiser_id FROM events WHERE event_id=?'); $o->execute([$b['event_id']]); if ((int)$o->fetchColumn() !== (int)self::$user['user_id']) self::notFound('Booking'); }
        if ($b['booking_status'] !== 'CONFIRMED') self::json(409,['success'=>false,'error'=>['code'=>'TICKET_NOT_CONFIRMED','message'=>'QR ticket is available only after booking confirmation.']]);
        if (!function_exists('curl_init')) self::json(503,['success'=>false,'error'=>['code'=>'CURL_UNAVAILABLE','message'=>'PHP cURL extension is required for the external QR service.']]);
        $payload = json_encode(['reference'=>$b['booking_reference'],'event'=>$b['title'],'event_date'=>$b['event_date'],'quantity'=>(int)$b['quantity']]);
        $url='https://api.qrserver.com/v1/create-qr-code/?size=240x240&format=png&data='.rawurlencode($payload);
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false]);
        $image=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE); $err=curl_error($ch); curl_close($ch);
        if ($image===false || $status!==200 || !str_contains($contentType,'image/png')) self::json(502,['success'=>false,'error'=>['code'=>'QR_PROVIDER_ERROR','message'=>'External QR provider did not return an image. Try again later.']]);
        self::json(200,['success'=>true,'data'=>['booking_reference'=>$b['booking_reference'],'provider'=>'goQR QRServer','content_type'=>'image/png','image_base64'=>base64_encode($image)]]);
    }

    private static function notFound(string $resource): never {
        self::json(404,['success'=>false,'error'=>['code'=>'NOT_FOUND','message'=>$resource.' not found.']]);
    }
    private static function methodNotAllowed(): never {
        header('Allow: GET, POST, PUT, PATCH, DELETE');
        self::json(405,['success'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'HTTP method is not supported for this resource.']]);
    }
}
