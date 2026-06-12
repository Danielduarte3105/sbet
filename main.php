<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

$users = loadJson(FILE_USERS);
$matches = loadJson(FILE_MATCHES);
$bets = loadJson(FILE_BETS);

$currentUser = null;
$userIndex = -1;

foreach ($users as $index => $u) {
    if ($u['id'] == $userId) {
        $currentUser = $u;
        $userIndex = $index;
        break;
    }
}

if (!$currentUser) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// LÓGICA DE CONTROLE DE LOOP DO PRIMEIRO LOGIN:
if (!isset($_SESSION['login_counted'])) {
    if (!isset($users[$userIndex]['login_count']) || $users[$userIndex]['login_count'] == 0) {
        $users[$userIndex]['login_count'] = 1;
        $currentUser['login_count'] = 1;
        saveJson(FILE_USERS, $users);
    } else {
        $currentUser['login_count'] = $users[$userIndex]['login_count'];
    }
    $_SESSION['login_counted'] = true;
} else {
    $currentUser['login_count'] = isset($users[$userIndex]['login_count']) ? $users[$userIndex]['login_count'] : 2;
}

$_SESSION['user_name'] = $currentUser['name'];
$_SESSION['user_email'] = $currentUser['email'];

// Separar jogos: Pendentes e Finalizados
$pendingMatches = [];
$finishedMatches = [];

foreach ($matches as $match) {
    if (empty($match['result'])) {
        $pendingMatches[] = $match;
    } else {
        $finishedMatches[] = $match;
    }
}

// Ordenar pendentes: mais próximos primeiro (data crescente)
usort($pendingMatches, function($a, $b) {
    $timeA = parseDateString($a['date']) ?: 0;
    $timeB = parseDateString($b['date']) ?: 0;
    return $timeA <=> $timeB;
});

// Finalizados: mais recentes primeiro (data decrescente)
usort($finishedMatches, function($a, $b) {
    $timeA = parseDateString($a['date']) ?: 0;
    $timeB = parseDateString($b['date']) ?: 0;
    return $timeB <=> $timeA;
});

// Função para buscar aposta do usuário
function getUserBet($bets, $userId, $matchId) {
    foreach ($bets as $b) {
        if ($b['user_id'] == $userId && $b['match_id'] == $matchId) return $b;
    }
    return null;
}

function canBet($matchDate) {
    $matchTime = parseDateString($matchDate);
    if ($matchTime === false) {
        return false;
    }
    $timeDiff = $matchTime - time();
    return $timeDiff > 1800; // 30 minutos em segundos
}

// Calcular Ranking
function calculateRanking($users, $bets, $matches) {
    $ranking = [];
    foreach ($users as $user) {
        $points = 0;
        foreach ($bets as $bet) {
            if ($bet['user_id'] != $user['id']) continue;
            foreach ($matches as $match) {
                if ($match['id'] == $bet['match_id'] && !empty($match['result'])) {
                    if ($bet['type'] === 'winner' && $bet['prediction'] === $match['result_winner']) {
                        $points += 1;
                    } elseif ($bet['type'] === 'score' && $bet['prediction'] === $match['result']) {
                        $points += 3;
                    } elseif ($bet['type'] === 'combo' && isset($bet['prediction_winner']) && isset($bet['prediction_score'])) {
                        $scoreCorrect = ($bet['prediction_score'] === $match['result']);
                        $winnerCorrect = ($bet['prediction_winner'] === $match['result_winner']);
                        
                        if ($scoreCorrect && $winnerCorrect) {
                            $points += 4;
                        } elseif ($scoreCorrect) {
                            $points += 3;
                        } elseif ($winnerCorrect) {
                            $points += 1;
                        }
                    }
                    break;
                }
            }
        }
        $ranking[] = ['name' => $user['name'], 'points' => $points, 'id' => $user['id']];
    }
    usort($ranking, fn($a, $b) => $b['points'] <=> $a['points']);
    return $ranking;
}

$ranking = calculateRanking($users, $bets, $matches);
$top10 = array_slice($ranking, 0, 7);
$otherRanks = array_slice($ranking, 7);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSFABET - Arena de Apostas Dev By Daniel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow-x: hidden;
        }

        /* Fundo animado com partículas */
        .background-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float linear infinite;
        }

        @keyframes float {
            0% {
                transform: translateY(100vh) translateX(0) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-20vh) translateX(100px) rotate(360deg);
                opacity: 0;
            }
        }

        /* Container principal */
        .main-container {
            position: relative;
            z-index: 2;
            padding: 2rem 1rem;
        }

        /* Navbar inovadora */
        .navbar-custom {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            margin-bottom: 2rem;
            padding: 1rem 2rem;
            transition: all 0.3s ease;
        }

        /* Chat Flutuante */
        .chat-bubble {
            position: fixed;
            top: 710px;
            right: 30px;
            z-index: 1000;
        }

        /* Ajuste para telas menores */
        @media (max-height: 800px) {
            .chat-bubble {
                top: 150px;
            }
        }

        @media (max-height: 600px) {
            .chat-bubble {
                top: 100px;
            }
        }

        .chat-window {
            position: fixed;
            bottom: auto;
            top: 50%;
            transform: translateY(-50%);
            right: 30px;
            width: 380px;
            height: 550px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            display: none;
            flex-direction: column;
            overflow: hidden;
            z-index: 1000;
            animation: slideUp 0.3s ease;
        }

        .chat-window.open {
            display: flex;
        }

        .chat-toggle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .chat-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .chat-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            animation: pulse 1s infinite;
        }

        .chat-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            padding: 1rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header h6 {
            margin: 0;
            font-weight: 600;
        }

        .chat-header h6 i {
            margin-right: 8px;
        }

        .chat-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .chat-close:hover {
            transform: scale(1.1);
        }

        .chat-tabs {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }

        .chat-tab {
            flex: 1;
            padding: 0.8rem;
            background: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #666;
        }

        .chat-tab.active {
            color: #667eea;
            border-bottom: 2px solid #667eea;
        }

        .chat-tab:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .chat-content {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }

        /* Histórico de Apostas */
        .bet-history-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 0.8rem;
            margin-bottom: 0.8rem;
            border-left: 3px solid #667eea;
            transition: all 0.3s ease;
        }

        .bet-history-item:hover {
            transform: translateX(3px);
            background: #e9ecef;
        }

        .bet-history-user {
            font-weight: 600;
            color: #667eea;
            margin-bottom: 0.3rem;
        }

        .bet-history-user i {
            margin-right: 5px;
        }

        .bet-history-match {
            font-size: 0.85rem;
            color: #333;
            margin-bottom: 0.3rem;
        }

        .bet-history-details {
            font-size: 0.8rem;
            color: #666;
        }

        .bet-history-details span {
            display: inline-block;
            background: #e9ecef;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            margin-right: 0.5rem;
        }

        /* Mensagens do Chat */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .message {
            display: flex;
            flex-direction: column;
            max-width: 85%;
        }

        .message-own {
            align-self: flex-end;
        }

        .message-other {
            align-self: flex-start;
        }

        .message-header {
            font-size: 0.7rem;
            margin-bottom: 0.2rem;
            padding: 0 0.5rem;
        }

        .message-own .message-header {
            text-align: right;
            color: #667eea;
        }

        .message-other .message-header {
            color: #764ba2;
        }

        .message-bubble {
            padding: 0.6rem 1rem;
            border-radius: 15px;
            font-size: 0.85rem;
            word-wrap: break-word;
        }

        .message-own .message-bubble {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message-other .message-bubble {
            background: #f0f0f0;
            color: #333;
            border-bottom-left-radius: 4px;
        }

        .message-time {
            font-size: 0.65rem;
            color: #999;
            margin-top: 0.2rem;
            padding: 0 0.5rem;
        }

        .message-own .message-time {
            text-align: right;
        }

        .chat-input-area {
            padding: 1rem;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 0.5rem;
            background: white;
        }

        .chat-input {
            flex: 1;
            padding: 0.6rem;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            outline: none;
            transition: all 0.3s ease;
        }

        .chat-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }

        .chat-send {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            border-radius: 50%;
            width: 38px;
            height: 38px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .chat-send:hover {
            transform: scale(1.05);
        }

        .empty-chat {
            text-align: center;
            color: #999;
            padding: 2rem;
        }

        .empty-chat i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .loading-spinner {
            text-align: center;
            padding: 1rem;
            color: #667eea;
        }

        /* Ajuste do botão scroll to top para não conflitar com o chat */
        .scroll-top-btn {
            bottom: 30px;
            right: 30px;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .chat-bubble {
                bottom: 30px;
                right: 20px;
                transform: none;
            }
            
            .chat-window {
                width: calc(100% - 40px);
                right: 20px;
                left: 20px;
                top: auto;
                bottom: 100px;
                transform: none;
                height: 450px;
            }
            
            .chat-toggle {
                width: 50px;
                height: 50px;
                font-size: 1.4rem;
            }
            
            .scroll-top-btn {
                bottom: 20px;
                right: 20px;
            }
        }

        .navbar-custom:hover {
            background: rgba(0, 0, 0, 0.4);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }

        .welcome-text {
            font-size: 1.1rem;
            font-weight: 500;
            color: white;
        }

        .welcome-text i {
            margin-right: 8px;
            color: #ffd700;
        }

        .welcome-text strong {
            color: white;
        }

        .site-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 1rem;
        }

        .site-title {
            font-size: 3.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #fff 0%, #ffd700 50%, #ffed4e 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 0 30px rgba(255, 215, 0, 0.3);
            letter-spacing: 2px;
            animation: glow 2s ease-in-out infinite alternate;
            margin-bottom: 0.5rem;
        }

        .site-subtitle {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
            letter-spacing: 3px;
            font-weight: 300;
        }

        @keyframes glow {
            from {
                text-shadow: 0 0 20px rgba(255, 215, 0, 0.3);
            }
            to {
                text-shadow: 0 0 40px rgba(255, 215, 0, 0.6);
            }
        }

        @media (max-width: 768px) {
            .site-title {
                font-size: 2.2rem;
            }
            
            .site-subtitle {
                font-size: 0.7rem;
                letter-spacing: 2px;
            }
        }

        @media (max-width: 480px) {
            .site-title {
                font-size: 1.8rem;
            }
        }

        .btn-custom {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            border-radius: 12px;
            padding: 0.5rem 1.2rem;
            transition: all 0.3s ease;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-custom:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-logout {
            background: rgba(220, 53, 69, 0.2);
        }

        .btn-logout:hover {
            background: rgba(220, 53, 69, 0.4);
        }

        /* Filtro de pesquisa */
        .search-filter {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .search-input {
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 12px;
            padding: 0.8rem 1rem;
            font-size: 1rem;
        }

        .search-input:focus {
            outline: none;
            box-shadow: 0 0 0 2px #667eea;
            background: white;
        }

        /* Tabs inovadoras */
        .tabs-custom {
            border: none;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: nowrap;
            overflow-x: auto;
        }

        .tabs-custom .nav-link {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 15px;
            padding: 0.8rem 1.2rem;
            color: white;
            font-weight: 500;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .tabs-custom .nav-link i {
            margin-right: 8px;
        }

        .tabs-custom .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .tabs-custom .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        /* Cards de jogos */
        .game-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .game-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
        }

        .game-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1rem;
            color: white;
        }

        .game-header h5 {
            margin: 0;
            font-weight: 600;
        }

        .game-header small {
            opacity: 0.9;
        }

        .game-body {
            padding: 1.5rem;
        }

        /* Alertas personalizados */
        .alert-bet {
            border-radius: 15px;
            border: none;
            padding: 1rem;
            margin-bottom: 1rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-bet-success {
            background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%);
            color: #1e5c2e;
        }

        .alert-warning-custom {
            background: linear-gradient(135deg, #ffeaa7, #fdcb6e);
            color: #d63031;
        }

        /* Aposta Combinada */
        .combo-bet-container {
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            border-radius: 15px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .scroll-top-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            color: white;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.3s ease;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .scroll-top-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
            background: linear-gradient(135deg, #764ba2, #667eea);
        }

        .scroll-top-btn.show {
            display: flex;
            animation: fadeInUp 0.3s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .scroll-top-btn {
                bottom: 20px;
                right: 20px;
                width: 45px;
                height: 45px;
                font-size: 1.2rem;
            }
        }

        .combo-selection {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .selection-group {
            flex: 1;
            min-width: 200px;
        }

        .selection-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .selection-group select {
            width: 100%;
            padding: 0.6rem;
            border-radius: 10px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .selection-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .points-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .points-4 {
            background: #ffd700;
            color: #333;
        }

        .btn-combo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 0.8rem;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn-combo:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-edit-bet {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            border: none;
            border-radius: 12px;
            padding: 0.5rem 1rem;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-edit-bet:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
        }

        .lock-icon {
            color: #dc3545;
            margin-left: 0.5rem;
        }

        /* Modal Personalizado */
        .modal-custom .modal-content {
            background: linear-gradient(135deg, #fff, #f8f9fa);
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-custom .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            border: none;
        }

        .modal-custom .modal-footer {
            border: none;
        }

        /* Ranking card */
        .ranking-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 2rem;
        }

        .ranking-header {
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            padding: 1.1rem;
            text-align: center;
            color: #333;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .ranking-header:hover {
            background: linear-gradient(135deg, #ffed4e, #ffd700);
        }

        .ranking-header h4 {
            margin: 0;
            font-weight: 500;
        }

        .ranking-header i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .ranking-table {
            margin: 0;
        }

        .ranking-table thead th {
            background: #f8f9fa;
            color: #333;
            font-weight: 600;
            border: none;
            padding: 1rem;
        }

        .ranking-table tbody tr {
            transition: all 0.3s ease;
        }

        .ranking-table tbody tr:hover {
            background: rgba(102, 126, 234, 0.1);
            transform: scale(1.01);
        }

        .rank-1 {
            background: linear-gradient(90deg, #ffd700, #fff8e1);
            font-weight: bold;
        }

        .rank-2 {
            background: linear-gradient(90deg, #c0c0c0, #f5f5f5);
        }

        .rank-3 {
            background: linear-gradient(90deg, #cd7f32, #fdf0e3);
        }

        .btn-show-all {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            border-radius: 12px;
            padding: 0.5rem 1rem;
            margin: 1rem auto;
            color: white;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            width: auto;
            min-width: 200px;
            max-width: 80%;
            display: block;
            text-align: center;
        }

        .btn-show-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .no-results {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 20px;
            padding: 3rem;
            text-align: center;
            color: #667eea;
        }

        .no-results i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 992px) {
            .main-container {
                padding: 1rem;
            }
            
            .navbar-custom {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .ranking-card {
                position: static;
                margin-top: 2rem;
            }
        }

        @media (max-width: 768px) {
            .combo-selection {
                flex-direction: column;
            }
            
            .tabs-custom .nav-link {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
        }

        .game-card {
            animation: fadeInUp 0.5s ease forwards;
        }
    </style>
</head>
<body>
    <div class="background-animation" id="particles"></div>

    <div class="main-container">
        <div class="container">

            <div class="site-header">
                <h1 class="site-title">SSFABET</h1>
                <div class="site-subtitle">ARENA DE APOSTAS</div>
                <div class="site-subtitle" style="font-size: 8px;">DEV BY DANIEL</div>
            </div>

            <div class="navbar-custom d-flex justify-content-between align-items-center">
                <div class="welcome-text">
                    <i class="fas fa-crown"></i> 
                    Bem-vindo, <strong><?= htmlspecialchars($currentUser['name']) ?></strong>
                </div>
                <div class="d-flex gap-2">
                    <?php if (strtolower($currentUser['name']) === 'admin' || strpos(strtolower($currentUser['email']), 'admin') !== false): ?>
                        <a href="admin.php" class="btn-custom">
                            <i class="fas fa-shield-alt"></i> Admin
                        </a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn-custom btn-logout">
                        <i class="fas fa-sign-out-alt"></i> Sair
                    </a>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-7">
                    <div class="search-filter">
                        <div class="input-group">
                            <span class="input-group-text bg-transparent border-0 text-white">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" class="form-control search-input" id="searchInput" placeholder="Pesquisar por time ou nome do jogo...">
                            <button class="btn btn-light ms-2 rounded-pill" id="clearSearch" style="display: none;">
                                <i class="fas fa-times"></i> Limpar
                            </button>
                        </div>
                    </div>

                    <ul class="nav nav-tabs tabs-custom" id="myTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="apostas-tab" data-bs-toggle="tab" data-bs-target="#apostas" type="button">
                                <i class="fas fa-gamepad"></i> Apostas Pendentes
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="registradas-tab" data-bs-toggle="tab" data-bs-target="#registradas" type="button">
                                <i class="fas fa-receipt"></i> Minhas Apostas
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="historico-tab" data-bs-toggle="tab" data-bs-target="#historico" type="button">
                                <i class="fas fa-history"></i> Histórico
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="apostas">
                            <div id="pendingMatchesContainer">
                                <?php 
                                $visiblePendingCount = 0;
                                foreach ($pendingMatches as $match): 
                                    $userBet = getUserBet($bets, $userId, $match['id']);
                                    // Se já apostou, não exibe nesta aba primária
                                    if ($userBet) continue; 
                                    $canBet = canBet($match['date']);
                                    $visiblePendingCount++;
                                ?>
                                <div class="game-card" data-match-id="<?= $match['id'] ?>" data-match-date="<?= $match['date'] ?>"
                                     data-team1="<?= htmlspecialchars(strtolower($match['team1'])) ?>" 
                                     data-team2="<?= htmlspecialchars(strtolower($match['team2'])) ?>"
                                     data-match-name="<?= htmlspecialchars(strtolower($match['team1'] . ' vs ' . $match['team2'])) ?>">
                                    <div class="game-header">
                                        <h5>
                                            <i class="fas fa-futbol"></i> 
                                            <?= htmlspecialchars($match['team1']) ?> <strong>VS</strong> <?= htmlspecialchars($match['team2']) ?>
                                        </h5>
                                        <small>
                                            <i class="far fa-calendar-alt"></i> 
                                            <?= date('d/m/Y H:i', strtotime($match['date'])) ?>
                                        </small>
                                    </div>
                                    <div class="game-body">
                                        <?php if ($canBet): ?>
                                            <div class="combo-bet-container">
                                                <h6 class="mb-3">
                                                    <i class="fas fa-gem"></i> Aposta Combinada
                                                    <span class="points-badge points-4">Até 4 pontos</span>
                                                </h6>
                                                <div class="combo-selection">
                                                    <div class="selection-group">
                                                        <label><i class="fas fa-trophy"></i> Quem Vence?</label>
                                                        <select id="winner_<?= $match['id'] ?>" class="form-select" onchange="updateScoreLabels(<?= $match['id'] ?>, '<?= addslashes($match['team1']) ?>', '<?= addslashes($match['team2']) ?>')">
                                                            <option value="">Selecione...</option>
                                                            <option value="home">🏠 <?= htmlspecialchars($match['team1']) ?></option>
                                                            <option value="draw">⚖️ Empate</option>
                                                            <option value="away">🏟️ <?= htmlspecialchars($match['team2']) ?></option>
                                                        </select>
                                                    </div>
                                                    <div class="selection-group">
                                                        <label id="score_label_<?= $match['id'] ?>"><i class="fas fa-chart-line"></i> Placar (Vencedor × Perdedor)</label>
                                                        <div class="d-flex gap-2 align-items-center">
                                                            <select id="score_first_<?= $match['id'] ?>" class="form-select">
                                                                <?php for($i=0; $i<=9; $i++): ?>
                                                                    <option value="<?= $i ?>"><?= $i ?></option>
                                                                <?php endfor; ?>
                                                            </select>
                                                            <span class="fw-bold">×</span>
                                                            <select id="score_second_<?= $match['id'] ?>" class="form-select">
                                                                <?php for($i=0; $i<=9; $i++): ?>
                                                                    <option value="<?= $i ?>"><?= $i ?></option>
                                                                <?php endfor; ?>
                                                            </select>
                                                        </div>
                                                        <small class="text-muted" id="score_helper_<?= $match['id'] ?>"></small>
                                                    </div>
                                                </div>
                                                <button class="btn-combo" onclick="confirmComboBet(<?= $match['id'] ?>, '<?= addslashes($match['team1']) ?>', '<?= addslashes($match['team2']) ?>')">
                                                    <i class="fas fa-check-circle"></i> Confirmar Aposta Combinada
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert-warning-custom p-3 rounded text-center">
                                                <i class="fas fa-hourglass-end"></i> Prazo encerrado! Não é possível apostar nesta partida.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <?php if ($visiblePendingCount === 0): ?>
                                    <div class="alert-bet alert-bet-success">
                                        <i class="fas fa-check-circle"></i> Não há novos jogos pendentes para apostar no momento.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div id="noPendingResults" class="no-results" style="display: none;">
                                <i class="fas fa-search"></i>
                                <h4>Nenhum jogo encontrado</h4>
                                <p>Tente buscar por outro time ou jogo</p>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="registradas">
                            <div id="registeredMatchesContainer">
                                <?php 
                                $visibleRegisteredCount = 0;
                                foreach ($pendingMatches as $match): 
                                    $userBet = getUserBet($bets, $userId, $match['id']);
                                    if (!$userBet) continue; 
                                    
                                    // Só exibe aqui se ainda puder editar. Se travar, vai para o histórico definitivo.
                                    $canBet = canBet($match['date']);
                                    if (!$canBet) continue; 
                                    
                                    $visibleRegisteredCount++;
                                ?>
                                <div class="game-card" data-team1="<?= htmlspecialchars(strtolower($match['team1'])) ?>" 
                                     data-team2="<?= htmlspecialchars(strtolower($match['team2'])) ?>"
                                     data-match-name="<?= htmlspecialchars(strtolower($match['team1'] . ' vs ' . $match['team2'])) ?>">
                                    <div class="game-header">
                                        <h5>
                                            <i class="fas fa-futbol"></i> 
                                            <?= htmlspecialchars($match['team1']) ?> <strong>VS</strong> <?= htmlspecialchars($match['team2']) ?>
                                        </h5>
                                        <small>
                                            <i class="far fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($match['date'])) ?>
                                            <span class="badge bg-success ms-2"><i class="fas fa-edit"></i> Editável</span>
                                        </small>
                                    </div>
                                    <div class="game-body">
                                        <div class="alert-bet alert-bet-success">
                                            <i class="fas fa-check-circle"></i> <strong>Sua aposta registrada:</strong><br>
                                            <?php if ($userBet['type'] === 'combo'): ?>
                                                <span class="badge bg-primary mt-2">Vencedor: <?= $userBet['prediction_winner'] === 'home' ? $match['team1'] : ($userBet['prediction_winner'] === 'away' ? $match['team2'] : 'Empate') ?></span>
                                                <span class="badge bg-info mt-2">Placar: <?= $userBet['prediction_score'] ?></span>
                                            <?php else: ?>
                                                <?= $userBet['type'] === 'winner' ? 'Vencedor: <strong>'.($userBet['prediction'] === 'home' ? $match['team1'] : ($userBet['prediction'] === 'away' ? $match['team2'] : 'Empate')).'</strong>' : 'Placar exato: <strong>'.$userBet['prediction'].'</strong>' ?>
                                            <?php endif; ?>
                                            
                                            <div class="mt-3">
                                                <button class="btn-edit-bet" onclick="openEditModal(<?= $match['id'] ?>, '<?= addslashes($match['team1']) ?>', '<?= addslashes($match['team2']) ?>')">
                                                    <i class="fas fa-edit"></i> Alterar Palpite
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <?php if ($visibleRegisteredCount === 0): ?>
                                    <div class="no-results bg-white border rounded p-4 text-center">
                                        <i class="fas fa-receipt text-muted mb-2" style="font-size: 2rem;"></i>
                                        <p class="text-muted mb-0">Você não possui nenhuma aposta editável no momento.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="historico">
                            <div id="finishedMatchesContainer">
                                
                                <?php foreach ($pendingMatches as $match): 
                                    $userBet = getUserBet($bets, $userId, $match['id']);
                                    if (!$userBet) continue;
                                    $canBet = canBet($match['date']);
                                    if ($canBet) continue; // Se ainda for editável, fica na aba de cima
                                ?>
                                <div class="game-card border-danger" data-team1="<?= htmlspecialchars(strtolower($match['team1'])) ?>" 
                                     data-team2="<?= htmlspecialchars(strtolower($match['team2'])) ?>"
                                     data-match-name="<?= htmlspecialchars(strtolower($match['team1'] . ' vs ' . $match['team2'])) ?>">
                                    <div class="game-header" style="background: linear-gradient(135deg, #4a5568, #2d3748);">
                                        <h5>
                                            <i class="fas fa-lock text-warning"></i> 
                                            <?= htmlspecialchars($match['team1']) ?> <strong>VS</strong> <?= htmlspecialchars($match['team2']) ?>
                                        </h5>
                                        <small>
                                            <i class="far fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($match['date'])) ?>
                                            <span class="badge bg-danger ms-2"><i class="fas fa-lock"></i> Registro Fechado</span>
                                        </small>
                                    </div>
                                    <div class="game-body">
                                        <div class="p-3 bg-light rounded mb-2">
                                            <i class="fas fa-receipt"></i> <strong>Seu Palpite Final:</strong><br>
                                            <?php if ($userBet['type'] === 'combo'): ?>
                                                <span class="badge bg-primary mt-2">Vencedor: <?= $userBet['prediction_winner'] === 'home' ? $match['team1'] : ($userBet['prediction_winner'] === 'away' ? $match['team2'] : 'Empate') ?></span>
                                                <span class="badge bg-info mt-2">Placar: <?= $userBet['prediction_score'] ?></span>
                                            <?php else: ?>
                                                <?= $userBet['type'] === 'winner' ? 'Vencedor: '.$userBet['prediction'] : 'Placar: '.$userBet['prediction'] ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="alert alert-secondary py-2 m-0 text-center small">
                                            <i class="fas fa-spinner fa-spin"></i> Partida em andamento ou aguardando preenchimento do resultado oficial.
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <?php foreach ($finishedMatches as $match): 
                                    $userBet = getUserBet($bets, $userId, $match['id']);
                                ?>
                                <div class="game-card" data-team1="<?= htmlspecialchars(strtolower($match['team1'])) ?>" 
                                     data-team2="<?= htmlspecialchars(strtolower($match['team2'])) ?>"
                                     data-match-name="<?= htmlspecialchars(strtolower($match['team1'] . ' vs ' . $match['team2'])) ?>">
                                    <div class="game-header">
                                        <h5>
                                            <i class="fas fa-check-circle"></i> 
                                            <?= htmlspecialchars($match['team1']) ?> <strong>VS</strong> <?= htmlspecialchars($match['team2']) ?>
                                        </h5>
                                        <small><i class="far fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($match['date'])) ?></small>
                                    </div>
                                    <div class="game-body">
                                        <div class="alert-bet alert-bet-success">
                                            <i class="fas fa-flag-checkered"></i> 
                                            <strong>Resultado final:</strong> 
                                            <span class="badge bg-success"><?= $match['result'] ?></span>
                                            <br>
                                            <small>Vencedor: <?= $match['result_winner'] === 'home' ? $match['team1'] : ($match['result_winner'] === 'away' ? $match['team2'] : 'Empate') ?></small>
                                        </div>

                                        <?php if ($userBet): ?>
                                            <div class="mt-3 p-3 bg-light rounded">
                                                <i class="fas fa-receipt"></i> <strong>Sua aposta:</strong><br>
                                                <?php if ($userBet['type'] === 'combo'): ?>
                                                    <span class="badge bg-primary mt-2">Vencedor: <?= $userBet['prediction_winner'] === 'home' ? $match['team1'] : ($userBet['prediction_winner'] === 'away' ? $match['team2'] : 'Empate') ?></span>
                                                    <span class="badge bg-info mt-2">Placar: <?= $userBet['prediction_score'] ?></span>
                                                <?php else: ?>
                                                    <?= $userBet['type'] === 'winner' ? 'Vencedor: ' . ($userBet['prediction'] === 'home' ? $match['team1'] : ($userBet['prediction'] === 'away' ? $match['team2'] : 'Empate')) : 'Placar exato: ' . $userBet['prediction'] ?>
                                                <?php endif; ?>
                                                
                                                <?php 
                                                    $won = false;
                                                    $points = 0;
                                                    if ($userBet['type'] === 'combo') {
                                                        $scoreCorrect = ($userBet['prediction_score'] === $match['result']);
                                                        $winnerCorrect = ($userBet['prediction_winner'] === $match['result_winner']);
                                                        
                                                        if ($scoreCorrect && $winnerCorrect) { $won = true; $points = 4; }
                                                        elseif ($scoreCorrect) { $won = true; $points = 3; }
                                                        elseif ($winnerCorrect) { $won = true; $points = 1; }
                                                    } elseif ($userBet['type'] === 'winner' && $userBet['prediction'] === $match['result_winner']) {
                                                        $won = true; $points = 1;
                                                    } elseif ($userBet['type'] === 'score' && $userBet['prediction'] === $match['result']) {
                                                        $won = true; $points = 3;
                                                    }
                                                ?>
                                                <br class="mb-2">
                                                <?php if ($won): ?>
                                                    <span class="badge bg-success"><i class="fas fa-check"></i> Acertou! +<?= $points ?> pontos</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><i class="fas fa-times"></i> Não acertou</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <?php if (empty($finishedMatches) && $visiblePendingCount === count($pendingMatches)): ?>
                                    <div class="alert-bet alert-bet-success">
                                        <i class="fas fa-info-circle"></i> Nenhum registro fechado ou finalizado no histórico.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div id="noHistoryResults" class="no-results" style="display: none;">
                                <i class="fas fa-search"></i>
                                <h4>Nenhum jogo encontrado</h4>
                                <p>Tente buscar por outro time ou jogo</p>
                            </div>
                        </div>
                    </div>
                </div>

                <button class="scroll-top-btn" id="scrollTopBtn">
                    <i class="fas fa-arrow-up"></i>
                </button>

                <div class="col-lg-5">
                    <div class="ranking-card">
                        <div class="ranking-header" id="rankingHeader">
                            <i class="fas fa-chart-line"></i>
                            <h4>Classificação Geral</h4>
                            <small>Ranking de apostadores</small>
                        </div>
                        <div class="table-responsive">
                            <table class="ranking-table table">
                                <thead>
                                    <tr>
                                        <th width="60"><i class="fas fa-hashtag"></i> Pos</th>
                                        <th><i class="fas fa-user"></i> Apostador</th>
                                        <th class="text-end"><i class="fas fa-star"></i> Pontos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top10 as $i => $r): ?>
                                    <tr class="<?= $i == 0 ? 'rank-1' : ($i == 1 ? 'rank-2' : ($i == 2 ? 'rank-3' : '')) ?>">
                                        <td class="text-center fw-bold">
                                            <?= $i + 1 ?>º
                                            <?php if ($i == 0): ?><i class="fas fa-crown text-warning"></i><?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($r['name']) ?>
                                            <?php if ($r['name'] == $currentUser['name']): ?>
                                                <span class="badge bg-primary ms-2">Você</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold fs-5"><?= $r['points'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (!empty($otherRanks)): ?>
                                    <button class="btn-show-all" id="showAllRankingBtn">
                                        <i class="fas fa-list"></i> Ver todas as posições (<?= count($otherRanks) + 10 ?>)
                                    </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-custom" id="rankingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trophy"></i> Classificação Geral Completa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table ranking-table">
                            <thead>
                                <tr>
                                    <th width="30">Pos</th>
                                    <th>Apostador</th>
                                    <th class="text-end">Pontos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ranking as $i => $r): ?>
                                <tr class="<?= $i == 0 ? 'rank-1' : ($i == 1 ? 'rank-2' : ($i == 2 ? 'rank-3' : '')) ?>">
                                    <td class="text-center fw-bold">
                                        <?= $i + 1 ?>º
                                        <?php if ($i == 0): ?><i class="fas fa-crown text-warning"></i><?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($r['name']) ?>
                                        <?php if ($r['name'] == $currentUser['name']): ?>
                                            <span class="badge bg-primary ms-2">Você</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold fs-5"><?= $r['points'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-custom" id="confirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-check-circle"></i> Confirmar Aposta</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button>
                    <button type="button" class="btn btn-primary" id="confirmBetBtn"><i class="fas fa-check"></i> Confirmar Aposta</button>
                </div>
            </div>
        </div>
    </div>

    <div class="chat-bubble">
        <button class="chat-toggle" id="chatToggle">
            <i class="fas fa-comments"></i>
            <span class="chat-badge" id="chatBadge" style="display: none;">0</span>
        </button>
    </div>

    <div class="chat-window" id="chatWindow">
        <div class="chat-header">
            <h6><i class="fas fa-comments"></i> Comunidade SSFABET</h6>
            <button class="chat-close" id="chatClose"><i class="fas fa-times"></i></button>
        </div>
        <div class="chat-tabs">
            <button class="chat-tab active" data-tab="chat"><i class="fas fa-comment"></i> Chat</button>
            <button class="chat-tab" data-tab="history"><i class="fas fa-history"></i> Apostas</button>
        </div>
        <div class="chat-content">
            <div class="chat-tab-content" id="chatTab" style="display: flex; flex-direction: column; height: 100%;">
                <div class="chat-messages" id="chatMessages">
                    <div class="empty-chat">
                        <i class="fas fa-comments"></i>
                        <p>Nenhuma mensagem ainda. Seja o primeiro a conversar!</p>
                    </div>
                </div>
                <div class="chat-input-area">
                    <input type="text" class="chat-input" id="chatInput" placeholder="Digite sua mensagem...">
                    <button class="chat-send" id="chatSend"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>
            <div class="chat-tab-content" id="historyTab" style="display: none; height: 100%; overflow-y: auto;">
                <div id="betHistoryList">
                    <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Carregando apostas...</div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-custom" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Aposta</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button>
                    <button type="button" class="btn btn-warning" id="editBetBtn"><i class="fas fa-save"></i> Salvar Alterações</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-custom" id="firstLoginModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-lock text-warning"></i> Primeiro Login Detectado!</h5>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Para garantir a segurança da sua conta, altere sua senha provisória antes de continuar.</p>
                    <form id="changePasswordForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Nova Senha</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" required minlength="6" placeholder="Digite a nova senha">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="new_password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Confirme a Nova Senha</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" required minlength="6" placeholder="Repita a nova senha">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-combo w-100" id="saveNewPasswordBtn"><i class="fas fa-save"></i> Atualizar Senha e Entrar</button>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Gerenciador global de Elementos e Labels Dinâmicos de Placar
    function updateScoreLabels(matchId, team1, team2) {
        const winner = document.getElementById(`winner_${matchId}`).value;
        const label = document.getElementById(`score_label_${matchId}`);
        const helper = document.getElementById(`score_helper_${matchId}`);
        if(!label || !helper) return;

        if (winner === 'home') {
            label.innerHTML = `<i class="fas fa-chart-line"></i> Placar (${team1} × ${team2})`;
            helper.textContent = `Primeiro número = gols do ${team1}`;
        } else if (winner === 'away') {
            label.innerHTML = `<i class="fas fa-chart-line"></i> Placar (${team2} × ${team1})`;
            helper.textContent = `Primeiro número = gols do ${team2}`;
        } else if (winner === 'draw') {
            label.innerHTML = `<i class="fas fa-chart-line"></i> Placar`;
            helper.textContent = `Ex: 1 × 1`;
        }
    }
</script>

<script>
// ==================== CHAT E HISTÓRICO DE APOSTAS DA COMUNIDADE ====================
let chatMessages = [];
let currentUserId = <?= $userId ?>;
let currentUserName = '<?= addslashes($currentUser['name']) ?>';
let pollingInterval = null;

function loadChatMessages() {
    fetch('chat_actions.php?action=get_messages')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                chatMessages = data.messages;
                displayChatMessages();
                updateChatBadge();
            }
        })
        .catch(() => {});
}

function displayChatMessages() {
    const container = document.getElementById('chatMessages');
    if (!container) return;

    if (chatMessages.length === 0) {
        container.innerHTML = `<div class="empty-chat"><i class="fas fa-comments"></i><p>Nenhuma mensagem ainda. Seja o primeiro!</p></div>`;
        return;
    }

    container.innerHTML = chatMessages.map(msg => {
        const isOwn = msg.user_id == currentUserId;
        const time = new Date(msg.created_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        return `
            <div class="message ${isOwn ? 'message-own' : 'message-other'}">
                <div class="message-header"><i class="fas fa-user"></i> ${escapeHtml(msg.user_name)}</div>
                <div class="message-bubble">${escapeHtml(msg.message)}</div>
                <div class="message-time">${time}</div>
            </div>
        `;
    }).join('');
    container.scrollTop = container.scrollHeight;
}

function loadBetHistory() {
    fetch('chat_actions.php?action=get_bets_history')
        .then(response => response.json())
        .then(data => {
            if (data.success) displayBetHistory(data.bets);
        })
        .catch(error => console.error('Erro ao carregar histórico:', error));
}

function displayBetHistory(bets) {
    const container = document.getElementById('betHistoryList');
    if (!container) return;
    
    if (bets.length === 0) {
        container.innerHTML = `<div class="empty-chat"><i class="fas fa-chart-line"></i><p>Nenhuma aposta registrada ainda.</p></div>`;
        return;
    }
    
    container.innerHTML = bets.map(bet => {
        let betDetails = '';
        if (bet.type === 'combo') {
            const winnerText = bet.prediction_winner === 'home' ? bet.team1 : (bet.prediction_winner === 'away' ? bet.team2 : 'Empate');
            betDetails = `<span><i class="fas fa-trophy"></i> ${winnerText}</span> <span><i class="fas fa-futbol"></i> ${bet.prediction_score}</span>`;
        } else if (bet.type === 'winner') {
            const winnerText = bet.prediction === 'home' ? bet.team1 : (bet.prediction === 'away' ? bet.team2 : 'Empate');
            betDetails = `<span><i class="fas fa-trophy"></i> ${winnerText}</span>`;
        } else {
            betDetails = `<span><i class="fas fa-futbol"></i> ${bet.prediction}</span>`;
        }
        
        const matchDate = new Date(bet.match_date).toLocaleDateString('pt-BR');
        const eventDate = new Date(bet.updated_at || bet.created_at).toLocaleString('pt-BR');
        const eventLabel = bet.updated_at ? 'Atualizada em' : 'Registrada em';
        
        return `
            <div class="bet-history-item">
                <div class="bet-history-user"><i class="fas fa-user-circle"></i> ${escapeHtml(bet.user_name)}</div>
                <div class="bet-history-match">
                    <i class="fas fa-futbol"></i> ${escapeHtml(bet.team1)} vs ${escapeHtml(bet.team2)}
                    <small class="text-muted"> - ${matchDate}</small>
                </div>
                <div class="bet-history-details">
                    ${betDetails}
                    <div class="text-muted small mt-2">${eventLabel}: ${eventDate}</div>
                </div>
            </div>
        `;
    }).join('');
}

function sendMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message) return;
    
    fetch('chat_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=send_message&message=${encodeURIComponent(message)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            input.value = '';
            loadChatMessages();
        } else {
            Swal.fire({ icon: 'error', title: 'Erro', text: data.message || 'Não foi possível enviar a mensagem', confirmButtonColor: '#667eea' });
        }
    })
    .catch(() => {
        Swal.fire({ icon: 'error', title: 'Erro de conexão', text: 'Não foi possível conectar ao servidor', confirmButtonColor: '#667eea' });
    });
}

function updateChatBadge() {
    const badge = document.getElementById('chatBadge');
    const chatWindow = document.getElementById('chatWindow');
    const lastMessageTime = localStorage.getItem('lastChatView');
    
    if (lastMessageTime && chatMessages.length > 0) {
        const newMessages = chatMessages.filter(msg => new Date(msg.created_at) > new Date(lastMessageTime));
        if (newMessages.length > 0 && !chatWindow.classList.contains('open')) {
            badge.style.display = 'flex';
            badge.textContent = newMessages.length > 9 ? '9+' : newMessages.length;
        } else {
            badge.style.display = 'none';
        }
    } else if (chatMessages.length > 0 && !chatWindow.classList.contains('open')) {
        badge.style.display = 'flex';
        badge.textContent = chatMessages.length > 9 ? '9+' : chatMessages.length;
    } else {
        badge.style.display = 'none';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function initChat() {
    const chatToggle = document.getElementById('chatToggle');
    const chatWindow = document.getElementById('chatWindow');
    const chatClose = document.getElementById('chatClose');
    const chatSend = document.getElementById('chatSend');
    const chatInput = document.getElementById('chatInput');
    const chatTabs = document.querySelectorAll('.chat-tab');
    
    if (!chatToggle) return;
    chatWindow.classList.add('chat-higher');
    
    chatToggle.addEventListener('click', () => {
        chatWindow.classList.toggle('open');
        if (chatWindow.classList.contains('open')) {
            localStorage.setItem('lastChatView', new Date().toISOString());
            document.getElementById('chatBadge').style.display = 'none';
            loadChatMessages();
            loadBetHistory();
            if (pollingInterval) clearInterval(pollingInterval);
            pollingInterval = setInterval(() => {
                if (chatWindow.classList.contains('open')) loadChatMessages();
            }, 5000);
        } else {
            if (pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; }
            updateChatBadge();
        }
    });
    
    chatClose.addEventListener('click', () => {
        chatWindow.classList.remove('open');
        if (pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; }
        updateChatBadge();
    });
    
    chatSend.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendMessage(); });
    
    chatTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const targetTab = tab.dataset.tab;
            chatTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById('chatTab').style.display = targetTab === 'chat' ? 'flex' : 'none';
            document.getElementById('historyTab').style.display = targetTab === 'history' ? 'block' : 'none';
            if (targetTab === 'history') loadBetHistory();
        });
    });
}
</script>

<script>
    let currentMatchId = null;
    let currentBetData = null;
    let currentEditMatchId = null;
    let currentEditTeam1 = null;
    let currentEditTeam2 = null;
    let confirmModal = null;
    let editModal = null;
    let rankingModal = null;

    function createParticles() {
        const container = document.getElementById('particles');
        if (!container) return;
        const particleCount = 50;
        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.classList.add('particle');
            const size = Math.random() * 5 + 2;
            const duration = Math.random() * 10 + 5;
            const delay = Math.random() * 5;
            const left = Math.random() * 100;
            particle.style.width = `${size}px`;
            particle.style.height = `${size}px`;
            particle.style.left = `${left}%`;
            particle.style.animationDuration = `${duration}s`;
            particle.style.animationDelay = `${delay}s`;
            container.appendChild(particle);
        }
    }

    function initSearchFilter() {
        const searchInput = document.getElementById('searchInput');
        const clearBtn = document.getElementById('clearSearch');
        
        function filterGames() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            const activeTab = document.querySelector('.tab-pane.active').id;
            
            if (activeTab === 'apostas') {
                const pendingGames = document.querySelectorAll('#pendingMatchesContainer .game-card');
                let hasVisible = false;
                pendingGames.forEach(game => {
                    const team1 = game.dataset.team1 || '';
                    const team2 = game.dataset.team2 || '';
                    const matchName = game.dataset.matchName || '';
                    const matches = searchTerm === '' || team1.includes(searchTerm) || team2.includes(searchTerm) || matchName.includes(searchTerm);
                    game.style.display = matches ? 'block' : 'none';
                    if (matches) hasVisible = true;
                });
                document.getElementById('noPendingResults').style.display = (!hasVisible && searchTerm !== '') ? 'block' : 'none';
            } else if (activeTab === 'registradas') {
                const regGames = document.querySelectorAll('#registeredMatchesContainer .game-card');
                regGames.forEach(game => {
                    const team1 = game.dataset.team1 || '';
                    const team2 = game.dataset.team2 || '';
                    const matchName = game.dataset.matchName || '';
                    const matches = searchTerm === '' || team1.includes(searchTerm) || team2.includes(searchTerm) || matchName.includes(searchTerm);
                    game.style.display = matches ? 'block' : 'none';
                });
            } else if (activeTab === 'historico') {
                const finishedGames = document.querySelectorAll('#finishedMatchesContainer .game-card');
                let hasVisible = false;
                finishedGames.forEach(game => {
                    const team1 = game.dataset.team1 || '';
                    const team2 = game.dataset.team2 || '';
                    const matchName = game.dataset.matchName || '';
                    const matches = searchTerm === '' || team1.includes(searchTerm) || team2.includes(searchTerm) || matchName.includes(searchTerm);
                    game.style.display = matches ? 'block' : 'none';
                    if (matches) hasVisible = true;
                });
                document.getElementById('noHistoryResults').style.display = (!hasVisible && searchTerm !== '') ? 'block' : 'none';
            }
            clearBtn.style.display = searchTerm !== '' ? 'inline-block' : 'none';
        }
        
        searchInput.addEventListener('input', filterGames);
        clearBtn.addEventListener('click', () => { searchInput.value = ''; filterGames(); });
        
        document.querySelectorAll('#myTab button').forEach(tab => {
            tab.addEventListener('shown.bs.tab', () => { filterGames(); });
        });
    }

    // CONFIRMAR APOSTA COMBINADA
    window.confirmComboBet = function(matchId, team1, team2) {
        const winnerSelect = document.getElementById(`winner_${matchId}`);
        const firstSelect  = document.getElementById(`score_first_${matchId}`);
        const secondSelect = document.getElementById(`score_second_${matchId}`);

        const winner = winnerSelect ? winnerSelect.value : '';
        const first  = firstSelect  ? firstSelect.value  : '0';
        const second = secondSelect ? secondSelect.value : '0';

        if (!winner) {
            Swal.fire({ icon: 'warning', title: 'Atenção!', text: 'Por favor, selecione o vencedor da partida!', confirmButtonColor: '#667eea' });
            return;
        }

        let score = (winner === 'away') ? `${second}-${first}` : `${first}-${second}`;
        let winnerText = (winner === 'home') ? team1 : ((winner === 'away') ? team2 : 'Empate');

        currentMatchId = matchId;
        currentBetData = {
            type: 'combo',
            winner: winner,
            winnerText: winnerText,
            score: score,
            scoreHome: winner === 'home' ? first : (winner === 'away' ? second : first),
            scoreAway: winner === 'home' ? second : (winner === 'away' ? first : second)
        };

        document.getElementById('modalBody').innerHTML = `
            <div class="text-center">
                <i class="fas fa-gem" style="font-size: 3rem; color: #667eea;"></i>
                <h5 class="mt-3">Confirmar Aposta Combinada</h5>
                <div class="alert alert-info mt-3">
                    <strong>${team1} VS ${team2}</strong><br><br>
                    <span class="badge bg-primary p-2">🏆 Vencedor: ${winnerText}</span><br><br>
                    <span class="badge bg-info p-2">⚽ Placar: ${score}</span>
                </div>
                <p class="mt-2">Deseja confirmar esta aposta?</p>
            </div>
        `;
        if (!confirmModal) confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
        confirmModal.show();
    };

    // SALVAR NOVA APOSTA
    document.getElementById('confirmBetBtn').addEventListener('click', function() {
        if (!currentMatchId || !currentBetData) return;
        const submitBtn = this;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

        const formData = new FormData();
        formData.append('match_id', currentMatchId);
        formData.append('type', currentBetData.type);
        formData.append('winner', currentBetData.winner);
        formData.append('score', currentBetData.score);
        formData.append('score_home', currentBetData.scoreHome);
        formData.append('score_away', currentBetData.scoreAway);

        fetch('save_combo_bet.php', { method: 'POST', body: formData })
        .then(async response => {
            const text = await response.text();
            Swal.fire({ icon: 'success', title: 'Aposta realizada!', showConfirmButton: false, timer: 1000 });
            setTimeout(() => window.location.href = 'index.php', 1000);
        })
        .catch(() => {
            setTimeout(() => window.location.href = 'index.php', 1000);
        });
    });

    // MODAL EDIÇÃO
    window.openEditModal = function(matchId, team1, team2) {
        currentEditMatchId = matchId;
        currentEditTeam1 = team1;
        currentEditTeam2 = team2;

        const editModalBody = document.getElementById('editModalBody');
        editModalBody.innerHTML = `<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Carregando sua aposta...</p></div>`;
        
        if (!editModal) editModal = new bootstrap.Modal(document.getElementById('editModal'));
        editModal.show();

        fetch(`get_current_bet.php?match_id=${matchId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.bet) {
                editModalBody.innerHTML = `<div class="alert alert-warning">Não foi possível carregar a aposta.</div>`;
                return;
            }
            const scoreParts = data.bet.prediction_score.split('-');
            let homeOptions = '', awayOptions = '';
            for (let i = 0; i <= 9; i++) {
                homeOptions += `<option value="${i}" ${i == scoreParts[0] ? 'selected' : ''}>${i}</option>`;
                awayOptions += `<option value="${i}" ${i == scoreParts[1] ? 'selected' : ''}>${i}</option>`;
            }
            editModalBody.innerHTML = `
                <div class="combo-bet-container">
                    <div class="selection-group mb-3">
                        <label>Quem vence?</label>
                        <select id="edit_winner" class="form-select">
                            <option value="home" ${data.bet.prediction_winner === 'home' ? 'selected' : ''}>${team1}</option>
                            <option value="draw" ${data.bet.prediction_winner === 'draw' ? 'selected' : ''}>Empate</option>
                            <option value="away" ${data.bet.prediction_winner === 'away' ? 'selected' : ''}>${team2}</option>
                        </select>
                    </div>
                    <div class="selection-group">
                        <label>Placar</label>
                        <div class="d-flex gap-2">
                            <select id="edit_score_home" class="form-select">${homeOptions}</select>
                            <select id="edit_score_away" class="form-select">${awayOptions}</select>
                        </div>
                    </div>
                </div>
            `;
        })
        .catch(() => { editModalBody.innerHTML = `<div class="alert alert-danger">Erro ao carregar aposta.</div>`; });
    };

    // SALVAR ALTERAÇÃO DE PALPITE
    document.getElementById('editBetBtn').addEventListener('click', function() {
        const winner = document.getElementById('edit_winner').value;
        const scoreHome = document.getElementById('edit_score_home').value;
        const scoreAway = document.getElementById('edit_score_away').value;
        const submitBtn = this;
        submitBtn.disabled = true;

        const formData = new FormData();
        formData.append('match_id', currentEditMatchId);
        formData.append('winner', winner);
        formData.append('score', `${scoreHome}-${scoreAway}`);

        fetch('update_combo_bet.php', { method: 'POST', body: formData })
        .then(() => {
            Swal.fire({ icon: 'success', title: 'Aposta atualizada!', showConfirmButton: false, timer: 1000 });
            setTimeout(() => window.location.href = 'index.php', 1000);
        });
    });

    // Password Toggle e Modais Complementares
    document.addEventListener('DOMContentLoaded', function() {
        createParticles();
        initSearchFilter();
        initChat();
        
        const loginCount = <?= isset($currentUser['login_count']) ? (int)$currentUser['login_count'] : 0 ?>;
        if (loginCount === 1) {
            new bootstrap.Modal(document.getElementById('firstLoginModal')).show();
        }

        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const input = document.getElementById(this.getAttribute('data-target'));
                const icon = this.querySelector('i');
                input.type = input.type === 'password' ? 'text' : 'password';
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
        });

        document.getElementById('saveNewPasswordBtn')?.addEventListener('click', function() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            if (password.length < 6 || password !== confirmPassword) {
                Swal.fire({ icon: 'error', title: 'Erro de validação', text: 'Verifique se as senhas coincidem e contêm 6+ dígitos' });
                return;
            }
            const formData = new FormData();
            formData.append('new_password', password);
            fetch('change_password.php', { method: 'POST', body: formData }).then(() => window.location.reload());
        });

        document.getElementById('showAllRankingBtn')?.addEventListener('click', () => {
            if(!rankingModal) rankingModal = new bootstrap.Modal(document.getElementById('rankingModal'));
            rankingModal.show();
        });

        const scrollTopBtn = document.getElementById('scrollTopBtn');
        window.addEventListener('scroll', () => {
            if (window.pageYOffset > 300) scrollTopBtn.classList.add('show');
            else scrollTopBtn.classList.remove('show');
        });
        scrollTopBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    });
</script>
</body>
</html>