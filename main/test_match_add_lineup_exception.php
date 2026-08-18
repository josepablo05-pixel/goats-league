<?php
// Test for PDOException handling in match.php

// 1. We mock the database environment
$sqliteDbPath = tempnam(sys_get_temp_dir(), 'testdb_') . '.sqlite';
putenv("DATABASE_URL=sqlite:" . $sqliteDbPath);

$pdo = new PDO("sqlite:" . $sqliteDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Prepare the database state with necessary tables
$pdo->exec("
    CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, logo TEXT);
    CREATE TABLE matches (id INTEGER PRIMARY KEY, team1_id INTEGER, team2_id INTEGER, status TEXT, voting_closed INTEGER, match_date TEXT, official_score_team1 INTEGER, official_score_team2 INTEGER, jornada INTEGER);
    CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT, team_id INTEGER, rating REAL, profile_picture TEXT);
    CREATE TABLE match_lineups (match_id INTEGER, team_id INTEGER, player_id INTEGER);
    CREATE TABLE match_events (id INTEGER PRIMARY KEY, match_id INTEGER, team_id INTEGER, event_type TEXT, player_id INTEGER, minute INTEGER, related_player_id INTEGER);
    CREATE TABLE match_ratings (id INTEGER PRIMARY KEY, match_id INTEGER, voter_id INTEGER, target_id INTEGER, rating REAL);
");

$pdo->exec("INSERT INTO teams (id, name, logo) VALUES (1, 'Team A', 'logoA.png'), (2, 'Team B', 'logoB.png')");
$pdo->exec("INSERT INTO matches (id, team1_id, team2_id, status, voting_closed, match_date, jornada) VALUES (1, 1, 2, 'pending', 0, '2023-10-01', 1)");
$pdo->exec("INSERT INTO users (id, username, role, team_id) VALUES (1, 'AdminUser', 'admin', 1)");

$pdo = null;

// Fake web request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'add_lineup';
$_POST['player_id'] = 10;
$_POST['team_id'] = 1;
$_GET['id'] = 1;

// Start session securely before include
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['profile_picture'] = 'default.png';

// Intercept output to check result cleanly
ob_start();

register_shutdown_function(function() use ($sqliteDbPath) {
    ob_end_clean();
    $expectedErrorMsg = 'Ha ocurrido un error al intentar añadir al jugador a la alineación. Por favor, inténtalo de nuevo.';
    if (isset($_SESSION['error']) && $_SESSION['error'] === $expectedErrorMsg) {
        echo "SUCCESS: Exception caught and session error correctly set.\n";
        unlink($sqliteDbPath);
        exit(0);
    } else {
        echo "FAILURE: Session error not set correctly.\n";
        if (isset($_SESSION['error'])) {
            echo "Session error was: " . $_SESSION['error'] . "\n";
        }
        unlink($sqliteDbPath);
        exit(1);
    }
});

// Suppress warnings as we're missing some $_SERVER keys that might trigger notices
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

include __DIR__ . '/match.php';
