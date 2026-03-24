<?php
declare(strict_types=1);

use Kreait\Firebase\Factory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {

    // ===============================
    // DATABASE CONNECTION (LOCAL TEST)
    // ===============================

    try {
        $pdo = new PDO(
            'mysql:host=host.docker.internal;port=3306;dbname=callofphoenix;charset=utf8',
            'root',
            ''
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die('Database connection failed: ' . $e->getMessage());
    }

    // ===============================
    // FIREBASE CONFIG
    // ===============================

    $serviceAccountPath = __DIR__ . '/../config/callofphoenix-firebase-adminsdk.json';
    $factory = (new Factory)->withServiceAccount($serviceAccountPath);
    $auth = $factory->createAuth();

    // ===============================
    // AUTH MIDDLEWARE
    // ===============================

    $checkAuth = function (Request $request, $handler) use ($auth) {

        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $idToken = substr($authHeader, 7);

        try {
            $auth->verifyIdToken($idToken);
            return $handler->handle($request);
        } catch (Exception $e) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode(['error' => 'Invalid or expired token']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    };

    // ===============================
    // ROOT
    // ===============================

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode([
            'status' => 'CallOfPhoenix API is running 🔥'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ===============================
    // LOGIN FIREBASE
    // ===============================

    $app->post('/users/login', function (Request $request, Response $response) use ($auth) {

        $data = $request->getParsedBody();

        if (empty($data['email']) || empty($data['password'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Email and password required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $signInResult = $auth->signInWithEmailAndPassword($data['email'], $data['password']);

            $response->getBody()->write(json_encode([
                'success' => true,
                'token' => $signInResult->idToken()
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid credentials'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    });

    // =====================================================
    // 🔥 FIREBASE USERS LIST
    // =====================================================

    $app->get('/users', function (Request $request, Response $response) use ($auth) {

        try {

            $usersList = [];

            foreach ($auth->listUsers() as $user) {
                $usersList[] = [
                    'uid' => $user->uid,
                    'email' => $user->email,
                    'displayName' => $user->displayName,
                    'createdAt' => $user->metadata->createdAt
                        ? $user->metadata->createdAt->format('Y-m-d H:i:s')
                        : null
                ];
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'users' => $usersList
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));

            return $response->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }

    })->add($checkAuth);

    // =====================================================
    // CRUD PROGRAMMES
    // =====================================================

    $app->get('/programmes', fn($req, $res) => $res->withHeader('Content-Type','application/json')
        ->write(json_encode($pdo->query("SELECT * FROM programmes")->fetchAll(PDO::FETCH_ASSOC)))
    )->add($checkAuth);

    $app->post('/programmes', function ($req, $res) use ($pdo) {
        $data = $req->getParsedBody();
        $pdo->prepare("INSERT INTO programmes (nom_programme, description, id_client, id_createur, date_debut, date_fin)
        VALUES (?,?,?,?,?,?)")->execute([
            $data['nom_programme'], $data['description'], $data['id_client'],
            $data['id_createur'], $data['date_debut'], $data['date_fin']
        ]);
        $res->getBody()->write(json_encode(['success'=>true]));
        return $res->withHeader('Content-Type','application/json');
    })->add($checkAuth);

    $app->put('/programmes/{id}', function ($req, $res, $args) use ($pdo) {
        $data = $req->getParsedBody();
        $pdo->prepare("UPDATE programmes SET nom_programme=?, description=? WHERE id_programme=?")
            ->execute([$data['nom_programme'], $data['description'], $args['id']]);
        $res->getBody()->write(json_encode(['success'=>true]));
        return $res->withHeader('Content-Type','application/json');
    })->add($checkAuth);

    $app->delete('/programmes/{id}', function ($req, $res, $args) use ($pdo) {
        $pdo->prepare("DELETE FROM programmes WHERE id_programme=?")->execute([$args['id']]);
        $res->getBody()->write(json_encode(['success'=>true]));
        return $res->withHeader('Content-Type','application/json');
    })->add($checkAuth);

    // =====================================================
    // CORS
    // =====================================================

    $app->options('/{routes:.*}', function ($req, $res) {
        return $res
            ->withHeader('Access-Control-Allow-Origin','*')
            ->withHeader('Access-Control-Allow-Methods','GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers','Content-Type, Authorization');
    });

};