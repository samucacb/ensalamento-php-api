<?php
// api/ensalamento.php - VERSÃO FINAL CORRIGIDA

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

// Bloco principal para tratar a requisição
// Em api/ensalamento.php

// Bloco principal para tratar a requisição - VERSÃO DE DEPURAÇÃO APRIMORADA
try {
    // A linha abaixo assume que seu arquivo database.php tem a função conectarBancoDeDados()
    // Se ele apenas cria a variável $database, use: global $database; $pdo = $database;
// --- VERSÃO CORRETA ---
global $database; // Torna a variável global $database acessível aqui.
$pdo = $database; // Usa a variável que já existe.

    
    $action = $_GET['action'] ?? '';

    if ($action === 'executar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        executarEnsalamento($pdo);
    } 
    // Adicione as outras ações aqui se precisar delas
    // elseif ($action === 'status') { ... }
    else {
        throw new Exception('Ação não permitida ou método incorreto.');
    }

} catch (Throwable $e) { // Mude de Exception para Throwable para capturar TODOS os erros
    http_response_code(500 );
    // Retorna o erro em um JSON válido, incluindo o arquivo e a linha
    echo json_encode([
        'success' => false,
        'message' => 'ERRO FATAL NO BACKEND: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}


// =======================================================
// FUNÇÕES DE LÓGICA
// =======================================================

function executarEnsalamento($database) {
    $input = $_POST;
    $periodo = $input['periodo'] ?? '2025.1';
    $algoritmo_tipo = $input['algoritmo'] ?? 'otimizado';

    $stmt_turmas = $database->prepare("SELECT * FROM turmas WHERE periodo = ? AND ativo = 1");
    $stmt_turmas->execute([$periodo]);
    $turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

    if (empty($turmas)) {
        throw new Exception('Nenhuma turma encontrada para o período ' . $periodo);
    }

    $stmt_salas = $database->prepare("SELECT * FROM salas WHERE ativo = 1 ORDER BY capacidade DESC");
    $stmt_salas->execute();
    $salas = $stmt_salas->fetchAll(PDO::FETCH_ASSOC);

    if (empty($salas)) {
        throw new Exception('Nenhuma sala disponível');
    }

    $inicio = microtime(true);
    $resultado = simularEnsalamento($turmas, $salas);
    $tempo_execucao = round((microtime(true) - $inicio) * 1000, 2);

    $database->beginTransaction();
    try {
        $stmt_delete = $database->prepare("DELETE FROM ensalamento WHERE periodo = ?");
        $stmt_delete->execute([$periodo]);

        $stmt_insert = $database->prepare(
            "INSERT INTO ensalamento (turma_id, sala_id, periodo, algoritmo_usado, eficiencia, status, dia_semana, horario_inicio, horario_fim) 
             VALUES (?, ?, ?, ?, ?, 'alocado', ?, ?, ?)"
        );

        foreach ($resultado['alocacoes'] as $alocacao) {
            $stmt_insert->execute([
                $alocacao['turma_id'], 
                $alocacao['sala_id'], 
                $periodo, 
                $algoritmo_tipo, 
                $alocacao['eficiencia'],
                $alocacao['dia_semana'],
                $alocacao['horario_inicio'],
                $alocacao['horario_fim']
            ]);
        }
        $database->commit();
    } catch (Exception $e) {
        $database->rollBack();
        throw $e; // Re-lança a exceção para ser capturada pelo bloco principal
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'periodo' => $periodo,
            'algoritmo' => $algoritmo_tipo,
            'total_turmas' => count($turmas),
            'turmas_alocadas' => $resultado['turmas_alocadas'],
            'conflitos' => $resultado['conflitos'],
            'eficiencia' => $resultado['eficiencia'],
            'tempo_execucao' => $tempo_execucao,
            'message' => 'Ensalamento executado com sucesso'
        ]
    ]);
}

// Em api/ensalamento.php

function simularEnsalamento($turmas, $salas) {
    $turmas_alocadas = 0;
    $conflitos = 0;
    $ocupacao_salas = [];
    $alocacoes = [];

    usort($turmas, function($a, $b) {
        return $b['num_alunos'] - $a['num_alunos'];
    });

    foreach ($turmas as $turma) {
        $sala_alocada = null;
        $dias_turma = [];
        if ($turma['segunda']) $dias_turma[] = 'segunda';
        if ($turma['terca']) $dias_turma[] = 'terca';
        if ($turma['quarta']) $dias_turma[] = 'quarta';
        if ($turma['quinta']) $dias_turma[] = 'quinta';
        if ($turma['sexta']) $dias_turma[] = 'sexta';
        if ($turma['sabado']) $dias_turma[] = 'sabado';

        if (empty($dias_turma)) continue;

        // ==========================================================
        // CORREÇÃO FINAL: Capturar os horários da turma aqui.
        // Usamos '08:00:00' como um padrão seguro caso os dados estejam nulos.
        // ==========================================================
        $horario_inicio_turma = $turma['horario_inicio'] ?? '08:00:00';
        $horario_fim_turma = $turma['horario_fim'] ?? '10:00:00';
        $horario_key = $horario_inicio_turma . '-' . $horario_fim_turma;

        foreach ($salas as $sala) {
            if ($sala['capacidade'] < $turma['num_alunos']) continue;

            $disponivel = true;
            foreach ($dias_turma as $dia) {
                if (isset($ocupacao_salas[$sala['id']][$dia][$horario_key])) {
                    $disponivel = false;
                    break;
                }
            }

            if ($disponivel) {
                $sala_alocada = $sala;
                break; 
            }
        }

        if ($sala_alocada) {
            foreach ($dias_turma as $dia) {
                $ocupacao_salas[$sala_alocada['id']][$dia][$horario_key] = $turma['id'];

                // Agora, usamos as variáveis que acabamos de criar.
                $alocacoes[] = [
                    'turma_id' => $turma['id'],
                    'sala_id' => $sala_alocada['id'],
                    'eficiencia' => round(($turma['num_alunos'] / $sala_alocada['capacidade']) * 100, 2),
                    'dia_semana' => $dia,
                    'horario_inicio' => $horario_inicio_turma,
                    'horario_fim' => $horario_fim_turma
                ];
            }
            $turmas_alocadas++;
        } else {
            $conflitos++;
        }
    }

    $eficiencia_geral = count($turmas) > 0 ? round(($turmas_alocadas / count($turmas)) * 100, 1) : 0;

    return [
        'turmas_alocadas' => $turmas_alocadas,
        'conflitos' => $conflitos,
        'eficiencia' => $eficiencia_geral,
        'alocacoes' => $alocacoes
    ];
}

?>
