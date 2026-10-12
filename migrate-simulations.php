<?php
/**
 * TestMate – one-time simulation content upgrade
 *
 * Run from PowerShell in C:\projects\testmate :
 *     C:\php\php.exe tools\migrate-simulations.php
 *
 * What it does (all inside ONE transaction, safe to run more than once):
 *   1. Backs up the SQLite database file next to the original.
 *   2. Repairs broken "u2014" text in simulation scenarios (should be "—").
 *   3. Corrects the emergency-vehicle data to "move LEFT and stop" (Namibia
 *      drives on the left; the page already showed this, now the data agrees).
 *   4. Adds two "incorrect" night-driving scenarios.
 *   5. Adds the missing quiz questions for School Zone, Emergency Vehicle and
 *      Night Driving simulations.
 *   6. Adds 8 new simulations with quiz questions:
 *      Gravel Road Driving, Animals on Namibian Roads, Overtaking Trucks Safely,
 *      Roundabouts, Pedestrian Crossings, Yield Signs, Railway Level Crossings,
 *      Wet Roads & Following Distance.
 *
 * It never deletes learner data, results or existing questions.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line only.');
}

require __DIR__ . '/../includes/db_connect.php'; // provides $conn and $databasePath

/** @var PDO $conn */
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function out(string $msg): void { fwrite(STDOUT, $msg . PHP_EOL); }
function j(array $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function nextId(PDO $c, string $table): int {
    // Tables migrated from MySQL use "id INTEGER NOT NULL" (no auto-increment).
    return (int)$c->query("SELECT COALESCE(MAX(id), 0) + 1 FROM $table")->fetchColumn();
}
function topicId(PDO $c, string $name): ?int {
    $s = $c->prepare('SELECT id FROM topics WHERE name = ? LIMIT 1');
    $s->execute([$name]);
    $id = $s->fetchColumn();
    return $id === false ? null : (int)$id;
}
function questionCount(PDO $c, int $simId): int {
    $s = $c->prepare('SELECT COUNT(*) FROM simulation_questions WHERE simulation_id = ?');
    $s->execute([$simId]);
    return (int)$s->fetchColumn();
}
function addQuestions(PDO $c, int $simId, array $questions): int {
    $ins = $c->prepare('INSERT INTO simulation_questions (id, simulation_id, scenario_index, question, correct_answer, explanation) VALUES (?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($questions as [$index, $text, $answer, $explanation]) {
        $ins->execute([nextId($c, 'simulation_questions'), $simId, $index, $text, $answer, $explanation]);
        $n++;
    }
    return $n;
}

/* ------------------------------------------------------------------ backup */
if (!empty($databasePath) && is_file($databasePath)) {
    $backup = preg_replace('/\.sqlite$/i', '', $databasePath) . '-backup-' . date('Ymd-His') . '.sqlite';
    if (!copy($databasePath, $backup)) {
        exit("Could not create a backup at $backup. Nothing was changed." . PHP_EOL);
    }
    out("Backup created: $backup");
}

/* ------------------------------------------------------------------ content */
$NEW = [
    [
        'title' => 'Gravel Road Driving',
        'topic' => 'Speed Limits',
        'type' => 'gravel_road',
        'description' => 'Drive safely on Namibian gravel roads: speed, dust, bends and loose surfaces.',
        'scenarios' => [
            ['vehicle_speed' => 80, 'condition' => 'dry', 'driver_action' => 'steady', 'label' => 'Gravel road at a steady 80 km/h with smooth steering', 'is_correct' => true,
             'explanation' => 'Correct — gravel gives much less grip than tar. Keep a moderate speed (around 80 km/h or less), keep left, steer smoothly and brake gently.'],
            ['vehicle_speed' => 120, 'condition' => 'loose', 'driver_action' => 'too_fast', 'label' => 'Gravel road at 120 km/h', 'is_correct' => false,
             'explanation' => 'Incorrect — at high speed on loose gravel the tyres lose grip and the car can slide or roll over. Slow down to a speed at which you stay in full control.'],
            ['vehicle_speed' => 80, 'condition' => 'dust', 'driver_action' => 'tailgate_dust', 'label' => 'Following closely inside a dust cloud', 'is_correct' => false,
             'explanation' => 'Incorrect — inside thick dust you cannot see oncoming vehicles, animals or potholes. Drop back until the dust clears and you can see the road ahead, and keep your headlights on.'],
            ['vehicle_speed' => 80, 'condition' => 'dust', 'driver_action' => 'hang_back', 'label' => 'Dropping back until the dust clears', 'is_correct' => true,
             'explanation' => 'Correct — staying far enough behind that the dust has settled lets you see the road and any oncoming traffic.'],
            ['vehicle_speed' => 60, 'condition' => 'bend', 'driver_action' => 'brake_before_bend', 'label' => 'Braking before a sharp bend', 'is_correct' => true,
             'explanation' => 'Correct — brake while the car is still travelling straight, before the bend, then drive through the bend at a steady speed. Braking hard in a gravel bend can make the car slide.'],
            ['vehicle_speed' => 100, 'condition' => 'bend', 'driver_action' => 'brake_hard_swerve', 'label' => 'Entering a bend too fast and braking hard', 'is_correct' => false,
             'explanation' => 'Incorrect — entering a gravel bend too fast and then braking hard and swerving causes the car to slide off the road. Slow down before the bend.'],
        ],
        'questions' => [
            [0, 'A driver travels at a steady 80 km/h on a gravel road with smooth steering. Was this correct?', 'correct'],
            [1, 'A driver travels at 120 km/h on loose gravel. Was this correct?', 'incorrect'],
            [2, 'A driver follows closely behind another vehicle inside its dust cloud. Was this correct?', 'incorrect'],
            [3, 'A driver drops back until the dust clears before continuing. Was this correct?', 'correct'],
            [4, 'A driver brakes before a sharp bend on gravel and drives through it steadily. Was this correct?', 'correct'],
            [5, 'A driver enters a gravel bend too fast, then brakes hard and swerves. Was this correct?', 'incorrect'],
        ],
    ],
    [
        'title' => 'Animals on Namibian Roads',
        'topic' => 'Animals on the Road',
        'type' => 'animal_crossing',
        'description' => 'Kudu, cattle and donkeys on rural roads: slow down, be ready to stop and do not swerve.',
        'scenarios' => [
            ['animal' => '🦌', 'species' => 'kudu', 'slows' => true, 'driver_action' => 'slow', 'time_of_day' => 'dusk', 'label' => 'Kudu next to the road at dusk — driver slows down', 'is_correct' => true,
             'explanation' => 'Correct — kudu are most active at dawn and dusk and can jump into the road without warning. Slow down early and be ready to stop.'],
            ['animal' => '🐄', 'species' => 'cow', 'slows' => false, 'driver_action' => 'maintain', 'time_of_day' => 'day', 'label' => 'Cattle next to the road — driver keeps the same speed', 'is_correct' => false,
             'explanation' => 'Incorrect — livestock on unfenced roads can walk into the road at any time. Slow down, give them space and be ready to stop.'],
            ['animal' => '🦌', 'species' => 'kudu', 'slows' => false, 'driver_action' => 'swerve', 'time_of_day' => 'dusk', 'label' => 'Swerving into the oncoming lane to avoid a kudu', 'is_correct' => false,
             'explanation' => 'Incorrect — swerving into the oncoming lane or off the road often causes a far worse crash. Brake firmly in a straight line and stay in your lane.'],
            ['animal' => '🫏', 'species' => 'donkey', 'slows' => true, 'driver_action' => 'slow', 'time_of_day' => 'night', 'label' => 'Donkey on the roadside at night — driver slows down', 'is_correct' => true,
             'explanation' => 'Correct — animals are very hard to see at night. Drive slower, use your headlights correctly and be ready to stop.'],
        ],
        'questions' => [
            [0, 'A driver sees a kudu next to the road at dusk and slows down early. Was this correct?', 'correct'],
            [1, 'A driver sees cattle next to the road and keeps the same speed. Was this correct?', 'incorrect'],
            [2, 'A driver swerves into the oncoming lane to avoid a kudu. Was this correct?', 'incorrect'],
            [3, 'A driver slows down for a donkey next to the road at night. Was this correct?', 'correct'],
        ],
    ],
    [
        'title' => 'Overtaking Trucks Safely',
        'topic' => 'Overtaking & Passing',
        'type' => 'truck_overtake',
        'description' => 'When it is safe — and when it is not — to overtake a slow heavy vehicle.',
        'scenarios' => [
            ['line' => 'broken', 'oncoming_traffic' => false, 'driver_action' => 'overtake', 'label' => 'Broken line, clear road ahead', 'is_correct' => true,
             'explanation' => 'Correct — overtake only where the line is broken and the road ahead is clear for the whole manoeuvre. Signal, pass on the right with space, and return to the left when you can see the truck in your rear-view mirror.'],
            ['line' => 'solid', 'oncoming_traffic' => false, 'driver_action' => 'overtake', 'label' => 'Overtaking across a solid barrier line', 'is_correct' => false,
             'explanation' => 'Incorrect — a solid barrier line means you may not cross it to overtake. Stay behind the truck until the line is broken and it is safe.'],
            ['line' => 'broken', 'oncoming_traffic' => true, 'driver_action' => 'overtake', 'label' => 'Overtaking with an oncoming vehicle close by', 'is_correct' => false,
             'explanation' => 'Incorrect — never start an overtake unless the road ahead is clearly free of oncoming traffic for the whole manoeuvre.'],
            ['line' => 'solid', 'oncoming_traffic' => true, 'driver_action' => 'wait', 'label' => 'Waiting behind the truck on a solid line', 'is_correct' => true,
             'explanation' => 'Correct — with a solid line and oncoming traffic, the safe and legal choice is to stay behind the truck at a safe following distance.'],
            ['line' => 'broken', 'oncoming_traffic' => false, 'driver_action' => 'cut_in', 'label' => 'Cutting in too soon in front of the truck', 'is_correct' => false,
             'explanation' => 'Incorrect — returning too close in front of a heavy vehicle forces it to brake hard. Return to the left only when there is a safe gap.'],
            ['line' => 'broken', 'visibility' => 'dust', 'oncoming_traffic' => false, 'driver_action' => 'overtake', 'label' => 'Overtaking blind in dust', 'is_correct' => false,
             'explanation' => 'Incorrect — if dust or anything else stops you seeing the road ahead, do not overtake. An oncoming vehicle may be hidden.'],
        ],
        'questions' => [
            [0, 'On a broken line with a clear road ahead, a driver signals and overtakes a truck. Was this correct?', 'correct'],
            [1, 'A driver overtakes a truck across a solid barrier line. Was this correct?', 'incorrect'],
            [2, 'A driver starts to overtake while an oncoming vehicle is close. Was this correct?', 'incorrect'],
            [3, 'With a solid line and oncoming traffic, a driver stays behind the truck. Was this correct?', 'correct'],
            [4, 'After overtaking, a driver cuts in just in front of the truck. Was this correct?', 'incorrect'],
            [5, 'A driver overtakes a truck when dust hides the road ahead. Was this correct?', 'incorrect'],
        ],
    ],
    [
        'title' => 'Roundabouts',
        'topic' => 'Right of Way',
        'type' => 'roundabout',
        'description' => 'Give way to traffic from the right, travel clockwise and signal before exiting.',
        'scenarios' => [
            ['traffic_from_right' => true, 'driver_action' => 'yield', 'exit' => 2, 'signals' => true, 'label' => 'Vehicle coming from the right — driver gives way', 'is_correct' => true,
             'explanation' => 'Correct — traffic in a roundabout moves clockwise and you give way to vehicles approaching from your right. Enter only when there is a safe gap.'],
            ['traffic_from_right' => true, 'driver_action' => 'no_yield', 'exit' => 2, 'signals' => true, 'label' => 'Entering without giving way', 'is_correct' => false,
             'explanation' => 'Incorrect — the vehicle approaching from the right had right of way. Stop at the yield line and wait for a safe gap.'],
            ['traffic_from_right' => false, 'driver_action' => 'enter', 'exit' => 1, 'signals' => true, 'label' => 'Clear roundabout — first exit with left indicator', 'is_correct' => true,
             'explanation' => 'Correct — the road was clear, so the driver entered after checking right and signalled left to take the first exit.'],
            ['direction' => 'anticlockwise', 'traffic_from_right' => true, 'driver_action' => 'enter', 'exit' => 2, 'label' => 'Driving around the wrong way', 'is_correct' => false,
             'explanation' => 'Incorrect — in Namibia you always travel clockwise around a roundabout, keeping the central island on your right.'],
            ['traffic_from_right' => false, 'driver_action' => 'enter', 'exit' => 3, 'signals' => false, 'label' => 'Taking the third exit without signalling', 'is_correct' => false,
             'explanation' => 'Incorrect — signal your intention, and indicate left before leaving the roundabout so other road users know what you will do.'],
        ],
        'questions' => [
            [0, 'A vehicle approaches from the right, so the driver waits at the yield line before entering. Was this correct?', 'correct'],
            [1, 'A driver enters the roundabout without giving way to a vehicle from the right. Was this correct?', 'incorrect'],
            [2, 'The roundabout is clear; the driver enters and signals left for the first exit. Was this correct?', 'correct'],
            [3, 'A driver drives anticlockwise around the roundabout. Was this correct?', 'incorrect'],
            [4, 'A driver takes the third exit without signalling. Was this correct?', 'incorrect'],
        ],
    ],
    [
        'title' => 'Pedestrian Crossings',
        'topic' => 'Right of Way',
        'type' => 'pedestrian_crossing',
        'description' => 'Give way to pedestrians at zebra crossings and never overtake a stopped car at a crossing.',
        'scenarios' => [
            ['pedestrian' => 'crossing', 'driver_action' => 'stop', 'label' => 'Pedestrian on the zebra crossing — driver stops', 'is_correct' => true,
             'explanation' => 'Correct — give way to pedestrians on a pedestrian (zebra) crossing. Stop before the crossing and wait until they are safely across.'],
            ['pedestrian' => 'crossing', 'driver_action' => 'drive_through', 'label' => 'Driving through while a pedestrian is crossing', 'is_correct' => false,
             'explanation' => 'Incorrect — a pedestrian on the crossing has right of way. You must stop and let them cross.'],
            ['pedestrian' => 'waiting', 'driver_action' => 'stop', 'label' => 'Pedestrian waiting at the kerb — driver stops', 'is_correct' => true,
             'explanation' => 'Correct — slow down when approaching a crossing and be ready to stop for pedestrians who are about to cross.'],
            ['pedestrian' => 'hidden', 'driver_action' => 'overtake_stopped', 'label' => 'Overtaking a car that stopped at the crossing', 'is_correct' => false,
             'explanation' => 'Incorrect — never overtake a vehicle that has stopped at a pedestrian crossing. It may have stopped for a pedestrian you cannot see.'],
        ],
        'questions' => [
            [0, 'A pedestrian is on the zebra crossing and the driver stops before it. Was this correct?', 'correct'],
            [1, 'A driver drives through while a pedestrian is crossing. Was this correct?', 'incorrect'],
            [2, 'A pedestrian waits at the kerb and the driver stops to let them cross. Was this correct?', 'correct'],
            [3, 'A driver overtakes a car that has stopped at a pedestrian crossing. Was this correct?', 'incorrect'],
        ],
    ],
    [
        'title' => 'Yield Signs',
        'topic' => 'Road Signs',
        'type' => 'yield_sign',
        'description' => 'Joining a main road at a yield sign: give way, and go only when there is a safe gap.',
        'scenarios' => [
            ['main_road_traffic' => true, 'driver_action' => 'yield', 'label' => 'Yield sign with traffic approaching from the right', 'is_correct' => true,
             'explanation' => 'Correct — a yield sign means you must give way to traffic on the road you are entering. Slow down, stop if necessary, and turn only when there is a safe gap.'],
            ['main_road_traffic' => true, 'driver_action' => 'proceed', 'label' => 'Pulling out in front of approaching traffic', 'is_correct' => false,
             'explanation' => 'Incorrect — pulling out in front of approaching traffic at a yield sign forces them to brake and can cause a collision.'],
            ['main_road_traffic' => false, 'driver_action' => 'proceed_clear', 'label' => 'Yield sign with the road clear', 'is_correct' => true,
             'explanation' => 'Correct — unlike a stop sign, a yield sign does not require you to stop if the road is clear. Slow down, look right, left and right again, then proceed.'],
        ],
        'questions' => [
            [0, 'At a yield sign a car approaches from the right, so the driver waits before turning. Was this correct?', 'correct'],
            [1, 'At a yield sign the driver pulls out in front of an approaching car. Was this correct?', 'incorrect'],
            [2, 'At a yield sign the road is clear, so the driver slows, checks and proceeds without stopping. Was this correct?', 'correct'],
        ],
    ],
    [
        'title' => 'Railway Level Crossings',
        'topic' => 'Road Signs',
        'type' => 'railway_crossing',
        'description' => 'Stop, look and listen at level crossings, and never stop on the tracks.',
        'scenarios' => [
            ['train' => 'approaching', 'warning_lights' => true, 'driver_action' => 'stop', 'label' => 'Train approaching, lights flashing — driver stops', 'is_correct' => true,
             'explanation' => 'Correct — stop behind the stop line when a train is approaching or the warning lights are flashing, and wait until the train has passed.'],
            ['train' => 'approaching', 'warning_lights' => true, 'driver_action' => 'drive_through', 'label' => 'Racing across in front of a train', 'is_correct' => false,
             'explanation' => 'Incorrect — never try to beat a train. A train cannot stop quickly or swerve to avoid you.'],
            ['train' => 'none', 'warning_lights' => false, 'driver_action' => 'stop_look_proceed', 'label' => 'No train — stop, look both ways, then cross', 'is_correct' => true,
             'explanation' => 'Correct — at a level crossing with a stop sign, stop, look both ways along the line and listen, then cross only when it is safe.'],
            ['train' => 'none', 'warning_lights' => false, 'driver_action' => 'stop_on_tracks', 'label' => 'Stopping on the railway line in a queue', 'is_correct' => false,
             'explanation' => 'Incorrect — never stop on a railway line. Only drive onto the crossing when there is space on the other side for your whole vehicle.'],
        ],
        'questions' => [
            [0, 'A train is approaching and the lights are flashing; the driver stops and waits. Was this correct?', 'correct'],
            [1, 'A driver races across the level crossing in front of an approaching train. Was this correct?', 'incorrect'],
            [2, 'No train is coming; the driver stops, looks both ways and then crosses. Was this correct?', 'correct'],
            [3, 'In slow traffic, a driver stops on the railway line. Was this correct?', 'incorrect'],
        ],
    ],
    [
        'title' => 'Wet Roads & Following Distance',
        'topic' => 'Wet Weather & Tyres',
        'type' => 'following_distance',
        'description' => 'Keep a safe following distance, and increase it on wet roads.',
        'scenarios' => [
            ['weather' => 'dry', 'gap_seconds' => 2, 'vehicle_speed' => 100, 'driver_action' => 'keep_gap', 'label' => 'Dry road, two-second following distance', 'is_correct' => true,
             'explanation' => 'Correct — in good conditions keep at least a two-second gap to the vehicle ahead so you have time to react and stop.'],
            ['weather' => 'rain', 'gap_seconds' => 1, 'vehicle_speed' => 100, 'driver_action' => 'tailgate', 'label' => 'Wet road, only one second behind', 'is_correct' => false,
             'explanation' => 'Incorrect — on a wet road it takes much longer to stop. One second is far too close; at least double your following distance in the rain.'],
            ['weather' => 'rain', 'gap_seconds' => 4, 'vehicle_speed' => 90, 'driver_action' => 'keep_gap', 'label' => 'Wet road, four-second following distance', 'is_correct' => true,
             'explanation' => 'Correct — doubling the gap to four seconds on a wet road gives enough distance to stop safely.'],
            ['weather' => 'rain', 'gap_seconds' => 3, 'vehicle_speed' => 120, 'driver_action' => 'brake_hard', 'label' => 'Too fast in the rain, then braking hard', 'is_correct' => false,
             'explanation' => 'Incorrect — in rain, reduce speed so you can slow down gently. Hard braking at high speed on a wet road can make the car skid or aquaplane.'],
        ],
        'questions' => [
            [0, 'On a dry road a driver keeps a two-second gap behind the vehicle ahead. Was this correct?', 'correct'],
            [1, 'On a wet road a driver follows only one second behind the vehicle ahead. Was this correct?', 'incorrect'],
            [2, 'On a wet road a driver keeps a four-second gap. Was this correct?', 'correct'],
            [3, 'A driver travels fast in the rain and brakes hard when traffic slows. Was this correct?', 'incorrect'],
        ],
    ],
];

$EXISTING_QUESTIONS = [
    'school_zone' => [
        [0, 'A driver travels at 60 km/h past a school where the posted limit is 40 km/h. Was this correct?', 'incorrect'],
        [1, 'On a weekend, a driver travels at 40 km/h where the posted limit is 60 km/h. Was this correct?', 'correct'],
        [2, 'Children are near the road and the driver slows to 30 km/h, ready to stop. Was this correct?', 'correct'],
    ],
    'emergency_vehicle' => [
        [0, 'An ambulance approaches from behind with its siren on. The driver moves to the left edge and stops. Was this correct?', 'correct'],
        [1, 'A fire engine approaches with its siren on. The driver moves left and stops to let it pass. Was this correct?', 'correct'],
        [2, 'A police vehicle without siren or lights is behind the driver, who continues normally and stays alert. Was this correct?', 'correct'],
    ],
    'night_driving' => [
        [0, 'On a dark, unlit road with no other traffic, the driver uses high beam. Was this correct?', 'correct'],
        [1, 'A vehicle approaches at night and the driver dips to low beam. Was this correct?', 'correct'],
        [2, 'In fog, the driver uses low beam and fog lights. Was this correct?', 'correct'],
        [3, 'A vehicle approaches at night and the driver keeps high beam on. Was this correct?', 'incorrect'],
        [4, 'In fog, the driver switches on high beam. Was this correct?', 'incorrect'],
    ],
];

/* ------------------------------------------------------------------ apply */
$report = [];
try {
    $conn->beginTransaction();

    // 1. Repair broken escape text in simulation scenarios.
    $fix = $conn->exec("UPDATE simulations SET scenario_data = REPLACE(REPLACE(REPLACE(scenario_data, 'u2014', '—'), 'u2013', '–'), 'u2019', '’') WHERE scenario_data LIKE '%u2014%' OR scenario_data LIKE '%u2013%' OR scenario_data LIKE '%u2019%'");
    $report[] = "Repaired text in $fix simulation(s)";

    // 2. Emergency vehicle data: move LEFT and stop.
    $s = $conn->query("SELECT id, scenario_data FROM simulations WHERE animation_type = 'emergency_vehicle'");
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data = json_decode((string)$row['scenario_data'], true);
        if (!is_array($data)) continue;
        $changed = false;
        foreach ($data as &$sc) {
            if (!is_array($sc) || ($sc['sirens'] ?? '') !== 'on') continue;
            if (in_array($sc['action'] ?? '', ['pull_over_right', 'clear_intersection'], true)) {
                $wasIntersection = ($sc['action'] ?? '') === 'clear_intersection';
                $sc['action'] = 'pull_over_left_stop';
                $sc['explanation'] = $wasIntersection
                    ? 'Correct — if you are in an intersection, clear it first, then move to the LEFT edge of the road and stop so the emergency vehicle can pass.'
                    : 'Correct — move to the LEFT edge of the road, slow down and stop so the emergency vehicle can pass. Continue only when it is safe.';
                $changed = true;
            }
        }
        unset($sc);
        if ($changed) {
            $conn->prepare('UPDATE simulations SET scenario_data = ? WHERE id = ?')->execute([j($data), (int)$row['id']]);
            $report[] = 'Emergency vehicle scenarios now say: move LEFT and stop';
        }
    }

    // 3. Night driving: add two incorrect scenarios (once).
    $s = $conn->query("SELECT id, scenario_data FROM simulations WHERE animation_type = 'night_driving' ORDER BY id LIMIT 1");
    if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode((string)$row['scenario_data'], true) ?: [];
        $hasWrong = (bool)array_filter($data, static fn($x) => is_array($x) && empty($x['is_correct']));
        if (!$hasWrong) {
            $data[] = ['condition' => 'oncoming_traffic', 'lights' => 'high_beam', 'is_correct' => false,
                'explanation' => 'Incorrect — high beam dazzles oncoming drivers. Dip to low beam when a vehicle approaches.'];
            $data[] = ['condition' => 'fog', 'lights' => 'high_beam', 'is_correct' => false,
                'explanation' => 'Incorrect — in fog, high beam reflects back and makes it harder to see. Use low beam, and fog lights if your vehicle has them.'];
            $conn->prepare('UPDATE simulations SET scenario_data = ? WHERE id = ?')->execute([j($data), (int)$row['id']]);
            $report[] = 'Night Driving: added 2 incorrect scenarios';
        }
    }

    // 4. Missing quiz questions for existing simulations.
    foreach ($EXISTING_QUESTIONS as $type => $questions) {
        $s = $conn->prepare('SELECT id, scenario_data FROM simulations WHERE animation_type = ? ORDER BY id LIMIT 1');
        $s->execute([$type]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || questionCount($conn, (int)$row['id']) > 0) continue;
        $data = json_decode((string)$row['scenario_data'], true) ?: [];
        $rows = [];
        foreach ($questions as [$i, $text, $answer]) {
            if (!isset($data[$i])) continue;
            $rows[] = [$i, $text, $answer, (string)($data[$i]['explanation'] ?? '')];
        }
        $n = addQuestions($conn, (int)$row['id'], $rows);
        $report[] = "Added $n quiz questions to '$type'";
    }

    // 5. New simulations.
    $insSim = $conn->prepare('INSERT INTO simulations (id, topic_id, title, description, animation_type, scenario_data, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)');
    foreach ($NEW as $sim) {
        $exists = $conn->prepare('SELECT id FROM simulations WHERE title = ? LIMIT 1');
        $exists->execute([$sim['title']]);
        if ($exists->fetchColumn() !== false) { $report[] = "Skipped '{$sim['title']}' (already exists)"; continue; }
        $id = nextId($conn, 'simulations');
        $insSim->execute([$id, topicId($conn, $sim['topic']), $sim['title'], $sim['description'], $sim['type'], j($sim['scenarios'])]);
        $rows = [];
        foreach ($sim['questions'] as [$i, $text, $answer]) {
            $rows[] = [$i, $text, $answer, (string)($sim['scenarios'][$i]['explanation'] ?? '')];
        }
        $n = addQuestions($conn, $id, $rows);
        $report[] = "Added simulation '{$sim['title']}' (" . count($sim['scenarios']) . " scenarios, $n questions)";
    }

    $conn->commit();
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    out('FAILED — nothing was changed: ' . $e->getMessage());
    exit(1);
}

foreach ($report as $line) out(' • ' . $line);
out('Done.');