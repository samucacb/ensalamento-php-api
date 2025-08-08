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

// --- VERSÃO CORRETA E FINAL ---
$stmt_insert = $database->prepare("
    INSERT INTO ensalamento (
        turma_id, sala_id, periodo, algoritmo_usado, eficiencia, 
        dia_semana, horario_inicio, horario_fim, status
    ) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");


foreach ($resultado['alocacoes'] as $alocacao) {
    $stmt_insert->execute([
        $alocacao['turma_id'], 
        $alocacao['sala_id'], 
        $periodo, 
        $algoritmo_tipo, 
        $alocacao['eficiencia'],
        $alocacao['dia_semana'],
        $alocacao['horario_inicio'],
        $alocacao['horario_fim'],
        $alocacao['status'] // <-- O 9º PARÂMETRO QUE ESTAVA FALTANDO
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

function simularEnsalamento($turmas, $salas) {
    $alocacoes_finais = [];
    $ocupacao_salas = []; // Estrutura: [sala_id][dia_semana][horario_key] = true

    // Criar um mapa de salas por ID para acesso rápido
    $salas_map = [];
    foreach ($salas as $sala) {
        $salas_map[$sala['id']] = $sala;
    }

    // Separar turmas para processamento prioritário
    $turmas_fixas = array_filter($turmas, fn($t) => !empty($t['sala_fixa_id']));
    $turmas_normais = array_filter($turmas, fn($t) => empty($t['sala_fixa_id']));

    // ==================================================================
    // ETAPA 1: Processar turmas com sala fixa (PRIORIDADE MÁXIMA)
    // ==================================================================
    foreach ($turmas_fixas as $turma) {
        $sala_id_fixo = $turma['sala_fixa_id'];
        $dias_turma = obterDiasDaTurma($turma);
        $horario_key = ($turma['horario_inicio'] ?? '00:00') . '-' . ($turma['horario_fim'] ?? '00:00');

        $conflito_detectado = false;
        if (!isset($salas_map[$sala_id_fixo])) {
            $conflito_detectado = true; // Sala fixa não existe
        } else {
            foreach ($dias_turma as $dia) {
                if (isset($ocupacao_salas[$sala_id_fixo][$dia][$horario_key])) {
                    $conflito_detectado = true; // Horário já ocupado
                    break;
                }
            }
        }

        if ($conflito_detectado) {
            $alocacoes_finais[] = criarRegistroDeConflito($turma);
        } else {
            $sala = $salas_map[$sala_id_fixo];
            foreach ($dias_turma as $dia) {
                $ocupacao_salas[$sala_id_fixo][$dia][$horario_key] = true; // Marcar como ocupado
                $alocacoes_finais[] = criarRegistroDeAlocacao($turma, $sala);
            }
        }
    }

    // ==================================================================
    // ETAPA 2: Processar turmas normais (com busca pela melhor sala)
    // ==================================================================
    foreach ($turmas_normais as $turma) {
        $melhor_sala_encontrada = null;
        $melhor_score = -1;
        $dias_turma = obterDiasDaTurma($turma);
        $horario_key = ($turma['horario_inicio'] ?? '00:00') . '-' . ($turma['horario_fim'] ?? '00:00');

        if (empty($dias_turma)) {
            $alocacoes_finais[] = criarRegistroDeConflito($turma);
            continue;
        }

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
                $sobra = $sala['capacidade'] - $turma['num_alunos'];
                $score = 1 / (1 + $sobra); // Quanto menor a sobra, maior o score
                if ($score > $melhor_score) {
                    $melhor_score = $score;
                    $melhor_sala_encontrada = $sala;
                }
            }
        }

        if ($melhor_sala_encontrada) {
            foreach ($dias_turma as $dia) {
                $ocupacao_salas[$melhor_sala_encontrada['id']][$dia][$horario_key] = true;
                $alocacoes_finais[] = criarRegistroDeAlocacao($turma, $melhor_sala_encontrada);
            }
        } else {
            $alocacoes_finais[] = criarRegistroDeConflito($turma);
        }
    }

    // Contagem final
    $turmas_alocadas = count(array_filter($alocacoes_finais, fn($a) => $a['status'] === 'alocado'));
    $conflitos = count(array_filter($alocacoes_finais, fn($a) => $a['status'] === 'conflito'));
    $eficiencia_geral = count($turmas) > 0 ? round(($turmas_alocadas / count($turmas)) * 100, 1) : 0;

// --- VERSÃO CORRIGIDA E FINAL ---

// Contagem final e precisa baseada nos resultados reais
$contagens = array_reduce($alocacoes_finais, function ($carry, $item) {
    if ($item['status'] === 'alocado') {
        $carry['alocadas']++;
    } else if ($item['status'] === 'conflito') {
        $carry['conflitos']++;
    }
    return $carry;
}, ['alocadas' => 0, 'conflitos' => 0]);

$eficiencia_geral = count($turmas) > 0 ? round(($contagens['alocadas'] / count($turmas)) * 100, 1) : 0;

// Retorna o objeto completo com todas as chaves necessárias
return [
    'turmas_alocadas' => $contagens['alocadas'],
    'conflitos' => $contagens['conflitos'],
    'eficiencia' => $eficiencia_geral,
    'alocacoes' => $alocacoes_finais // A lista detalhada para o INSERT
];

}

// ==================================================================
// FUNÇÕES AUXILIARES (Adicione estas 3 funções no mesmo arquivo)
// ==================================================================

function obterDiasDaTurma($turma) {
    $dias = [];
    if (!empty($turma['segunda'])) $dias[] = 'segunda';
    if (!empty($turma['terca'])) $dias[] = 'terca';
    if (!empty($turma['quarta'])) $dias[] = 'quarta';
    if (!empty($turma['quinta'])) $dias[] = 'quinta';
    if (!empty($turma['sexta'])) $dias[] = 'sexta';
    if (!empty($turma['sabado'])) $dias[] = 'sabado';
    return $dias;
}

function criarRegistroDeAlocacao($turma, $sala) {
    return [
        'turma_id' => $turma['id'],
        'sala_id' => $sala['id'],
        'status' => 'alocado',
        'eficiencia' => round(($turma['num_alunos'] / $sala['capacidade']) * 100, 2),
        'dia_semana' => obterDiasDaTurma($turma)[0], // Salva o primeiro dia para referência
        'horario_inicio' => $turma['horario_inicio'],
        'horario_fim' => $turma['horario_fim']
    ];
}

// Em api/ensalamento.php

function criarRegistroDeConflito($turma) {
    return [
        'turma_id' => $turma['id'],
        'sala_id' => null,
        'status' => 'conflito', // <-- A LINHA QUE ESTAVA FALTANDO
        'eficiencia' => 0,
        'dia_semana' => obterDiasDaTurma($turma)[0] ?? 'indefinido',
        'horario_inicio' => $turma['horario_inicio'] ?? null,
        'horario_fim' => $turma['horario_fim'] ?? null
    ];
}


?>
