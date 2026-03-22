<?php
require_once __DIR__ . '/db.php';

// Build full standings from finished matches
$rawTeams = $pdo->query("SELECT id, name, points FROM teams ORDER BY name")->fetchAll();

$teams = [];
foreach ($rawTeams as $t) {
    $tid = $t['id'];

    // Matches where this team played as team1
    $s1 = $pdo->prepare("
        SELECT team1_score AS gf, team2_score AS gc
        FROM matches WHERE team1_id = ? AND status = 'finished'
    ");
    $s1->execute([$tid]);
    $as_team1 = $s1->fetchAll();

    // Matches where this team played as team2
    $s2 = $pdo->prepare("
        SELECT team2_score AS gf, team1_score AS gc
        FROM matches WHERE team2_id = ? AND status = 'finished'
    ");
    $s2->execute([$tid]);
    $as_team2 = $s2->fetchAll();

    $all = array_merge($as_team1, $as_team2);
    $pj = count($all);
    $v = $e = $d = $gf = $gc = 0;

    foreach ($all as $m) {
        $gf += (int)$m['gf'];
        $gc += (int)$m['gc'];
        if ($m['gf'] > $m['gc'])      $v++;
        elseif ($m['gf'] === $m['gc']) $e++;
        else                           $d++;
    }

    $teams[] = [
        'id'     => $tid,
        'name'   => $t['name'],
        'points' => $t['points'],
        'pj'     => $pj,
        'v'      => $v,
        'e'      => $e,
        'd'      => $d,
        'gf'     => $gf,
        'gc'     => $gc,
        'dg'     => $gf - $gc,
    ];
}

// Sort by points DESC, then goal diff DESC
usort($teams, function($a, $b) {
    if ($b['points'] !== $a['points']) return $b['points'] - $a['points'];
    return $b['dg'] - $a['dg'];
});
?>