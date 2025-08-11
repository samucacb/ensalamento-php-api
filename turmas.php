<?php
// Desabilitar TODOS os erros PHP para garantir JSON válido
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Capturar qualquer erro e retornar JSON
set_error_handler(function($severity, $message, $file, $line) {
    return true;
});


/**
 * API Turmas
 * Gerencia operações CRUD de turmas
 */

// Suprimir erros PHP para garantir JSON válido
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

try {
    $action = $_GET['action'] ?? '';
    
    // Inicializar conexão com banco
    global $database;
    
    switch ($action) {
        case 'listar':
            listarTurmas();
            break;
            
        case 'buscar':
            if (empty($_GET['id'])) {
                throw new Exception('ID da turma é obrigatório');
            }
            buscarTurma($_GET['id']);
            break;
            
        case 'criar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            criarTurma();
            break;
            
        case 'atualizar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            atualizarTurma();
            break;
            
        case 'excluir':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            excluirTurma();
            break;
            
        case 'estatisticas':
            obterEstatisticas();
            break;
            
        case 'cursos':
            obterCursos();
            break;
            
        default:
            throw new Exception('Ação não reconhecida');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Em api/turmas.php

function listarTurmas() {
    global $database;
    
    try {
        // Seus filtros e paginação continuam iguais
        $filtros = [
            'periodo' => $_GET['periodo'] ?? '2025.1', // Adicionei um padrão para segurança
            'curso' => $_GET['curso'] ?? '',
            'professor' => $_GET['professor'] ?? '',
            'busca' => $_GET['busca'] ?? ''
        ];
        
        $pagina = (int)($_GET['pagina'] ?? 1);
        $limite = (int)($_GET['limite'] ?? 20);
        
        // ==========================================================
        // CORREÇÃO PRINCIPAL APLICADA AQUI
        // ==========================================================

        // 1. A base da consulta agora inclui o LEFT JOIN
        $sql_base = "FROM turmas t LEFT JOIN ensalamento e ON t.id = e.turma_id AND e.periodo = :periodo_join";
        
        // 2. A cláusula WHERE começa com 1=1 para facilitar a adição de filtros
        $where = ['1=1'];
        
        // 3. Os parâmetros agora incluem o período para o JOIN
        $params = [
            ':periodo_join' => $filtros['periodo']
        ];
        
        // A sua lógica de filtros continua a mesma, mas usando 't.' para evitar ambiguidade
        if (!empty($filtros['periodo'])) {
            $where[] = 't.periodo = :periodo';
            $params[':periodo'] = $filtros['periodo'];
        }
        
        if (!empty($filtros['curso'])) {
            $where[] = 't.curso = :curso';
            $params[':curso'] = $filtros['curso'];
        }
        
        if (!empty($filtros['professor'])) {
            $where[] = 't.professor LIKE :professor';
            $params[':professor'] = '%' . $filtros['professor'] . '%';
        }
        
        if (!empty($filtros['busca'])) {
            $where[] = '(t.codigo LIKE :busca OR t.nome LIKE :busca)';
            $params[':busca'] = '%' . $filtros['busca'] . '%';
        }
        
        $offset = ($pagina - 1) * $limite;
        
// Em api/turmas.php, dentro da função listarTurmas()

// =================================================================
// CONSULTA SQL FINAL E COMPLETA
// =================================================================
$sql = "SELECT 
            t.*, 
            MAX(e.status) as status_ensalamento,
            -- Agrega os dias da semana em uma única string
            GROUP_CONCAT(DISTINCT e.dia_semana ORDER BY FIELD(e.dia_semana, 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado') SEPARATOR ', ') as dias_semana,
            -- Pega o primeiro horário encontrado (eles devem ser os mesmos para a mesma turma)
            MIN(e.horario_inicio) as horario_inicio,
            MIN(e.horario_fim) as horario_fim
        " . $sql_base . " 
        WHERE " . implode(' AND ', $where) . " 
        GROUP BY t.id 
        ORDER BY t.codigo 
        LIMIT " . $limite . " OFFSET " . $offset;
// =================================================================


        
        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        $turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ==========================================================
        // FIM DA CORREÇÃO
        // ==========================================================
        
        // A sua lógica para contar o total continua a mesma
        $sql_count = "SELECT COUNT(t.id) as total " . $sql_base . " WHERE " . implode(' AND ', $where);
        $stmt_count = $database->prepare($sql_count);
        $stmt_count->execute($params);
        $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
        
        // A sua resposta JSON continua a mesma
        echo json_encode([
            'success' => true,
            'data' => $turmas,
            'total' => $total,
            'pagina' => $pagina,
            'limite' => $limite,
            'total_paginas' => ceil($total / $limite)
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao listar turmas: ' . $e->getMessage()
        ]);
    }
}


function obterEstatisticas() {
    global $database;
    
    try {
        $periodo = $_GET['periodo'] ?? '2025.1';
        
        $stmt = $database->prepare("
            SELECT 
                COUNT(*) as total_turmas,
                COALESCE(SUM(num_alunos), 0) as total_alunos,
                COALESCE(AVG(num_alunos), 0) as media_alunos
            FROM turmas 
            WHERE periodo = :periodo
        ");
        $stmt->execute(['periodo' => $periodo]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao obter estatísticas: ' . $e->getMessage()
        ]);
    }
}

function obterCursos() {
    global $database;
    
    try {
        // Buscar cursos únicos das turmas
        $stmt = $database->prepare("
            SELECT DISTINCT curso 
            FROM turmas 
            WHERE ativo = 1 AND curso IS NOT NULL AND curso != ''
            ORDER BY curso
        ");
        $stmt->execute();
        $cursos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode([
            'success' => true,
            'data' => $cursos
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao carregar cursos: ' . $e->getMessage()
        ]);
    }
}

function buscarTurma($id) {
    global $database;
    
    try {
        $stmt = $database->prepare("
            SELECT 
                id,
                codigo,
                nome,
                curso,
                periodo,
                professor,
                num_alunos,
                carga_horaria,
                horario_inicio,
                horario_fim,
                segunda,
                terca,
                quarta,
                quinta,
                sexta,
                sabado,
                turno,
                observacoes,
                ativo,
                created_at
            FROM turmas 
            WHERE id = ? AND ativo = 1
        ");
        
        $stmt->execute([$id]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$turma) {
            throw new Exception('Turma não encontrada');
        }
        
        echo json_encode([
            'success' => true,
            'data' => $turma
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao buscar turma: ' . $e->getMessage()
        ]);
    }
}

function criarTurma() {
    global $database;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        // Validações
        if (empty($input['codigo'])) {
            throw new Exception('Código da turma é obrigatório');
        }
        
        if (empty($input['nome'])) {
            throw new Exception('Nome da turma é obrigatório');
        }
        
        if (empty($input['curso'])) {
            throw new Exception('Curso é obrigatório');
        }
        
        if (empty($input['periodo'])) {
            throw new Exception('Período é obrigatório');
        }
        
        if (empty($input['professor'])) {
            throw new Exception('Professor é obrigatório');
        }
        
        if (empty($input['num_alunos']) || $input['num_alunos'] <= 0) {
            throw new Exception('Número de alunos deve ser maior que zero');
        }
        
        // Verificar se código já existe
        $stmt = $database->prepare("SELECT id FROM turmas WHERE codigo = ? AND ativo = 1");
        $stmt->execute([$input['codigo']]);
        
        if ($stmt->fetch()) {
            throw new Exception('Já existe uma turma com este código');
        }
        
        // Inserir turma
$stmt = $database->prepare("
    INSERT INTO turmas (
        codigo, nome, curso, periodo, professor, num_alunos, carga_horaria, 
        horario_inicio, horario_fim, segunda, terca, quarta, quinta, sexta, sabado, 
        turno, observacoes, ativo, sala_fixa_id 
    ) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
        $ativo = isset($input['ativa']) ? ($input['ativa'] == '1' ? 1 : 0) : 1;
        
        // Processar dias da semana conforme sugestão
        $segunda = isset($input['segunda']) ? 1 : 0;
        $terca = isset($input['terca']) ? 1 : 0;
        $quarta = isset($input['quarta']) ? 1 : 0;
        $quinta = isset($input['quinta']) ? 1 : 0;
        $sexta = isset($input['sexta']) ? 1 : 0;
        $sabado = isset($input['sabado']) ? 1 : 0;
        $sala_fixa_id = !empty($input['sala_fixa_id']) ? (int)$input['sala_fixa_id'] : null;
        
        $stmt->execute([
            $input['codigo'],
            $input['nome'],
            $input['curso'],
            $input['periodo'],
            $input['professor'],
            $input['num_alunos'],
            $input['carga_horaria'] ?? 0,
            $input['horario_inicio'] ?? null,
            $input['horario_fim'] ?? null,
            $segunda,
            $terca,
            $quarta,
            $quinta,
            $sexta,
            $sabado,
            $input['turno'] ?? 'matutino',
            $input['observacoes'] ?? null,
            $ativo,
            $sala_fixa_id
        ]);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $database->lastInsertId(),
                'message' => 'Turma criada com sucesso'
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao criar turma: ' . $e->getMessage()
        ]);
    }
}

function atualizarTurma() {
    global $database;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        if (empty($input['id'])) {
            throw new Exception('ID da turma é obrigatório');
        }
        
        // Verificar se turma existe
        $stmt = $database->prepare("SELECT id FROM turmas WHERE id = ? AND ativo = 1");
        $stmt->execute([$input['id']]);
        
        if (!$stmt->fetch()) {
            throw new Exception('Turma não encontrada');
        }
        
        // Verificar código duplicado (exceto a própria turma)
        if (!empty($input['codigo'])) {
            $stmt = $database->prepare("SELECT id FROM turmas WHERE codigo = ? AND id != ? AND ativo = 1");
            $stmt->execute([$input['codigo'], $input['id']]);
            
            if ($stmt->fetch()) {
                throw new Exception('Já existe uma turma com este código');
            }
        }
        
        // Atualizar turma
        $stmt = $database->prepare("
            UPDATE turmas SET 
                codigo = COALESCE(?, codigo),
                nome = COALESCE(?, nome),
                curso = COALESCE(?, curso),
                periodo = COALESCE(?, periodo),
                professor = COALESCE(?, professor),
                num_alunos = COALESCE(?, num_alunos),
                carga_horaria = COALESCE(?, carga_horaria),
                horario_inicio = COALESCE(?, horario_inicio),
                horario_fim = COALESCE(?, horario_fim),
                segunda = COALESCE(?, segunda),
                terca = COALESCE(?, terca),
                quarta = COALESCE(?, quarta),
                quinta = COALESCE(?, quinta),
                sexta = COALESCE(?, sexta),
                sabado = COALESCE(?, sabado),
                turno = COALESCE(?, turno),
                observacoes = COALESCE(?, observacoes),
                ativo = COALESCE(?, ativo),
                sala_fixa_id = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $ativo = isset($input['ativa']) ? ($input['ativa'] == '1' ? 1 : 0) : null;
        
        // Processar dias da semana para UPDATE
        $segunda = isset($input['segunda']) ? 1 : 0;
        $terca = isset($input['terca']) ? 1 : 0;
        $quarta = isset($input['quarta']) ? 1 : 0;
        $quinta = isset($input['quinta']) ? 1 : 0;
        $sexta = isset($input['sexta']) ? 1 : 0;
        $sabado = isset($input['sabado']) ? 1 : 0;
        $sala_fixa_id = !empty($input['sala_fixa_id']) ? (int)$input['sala_fixa_id'] : null;

        $stmt->execute([
            $input['codigo'] ?? null,
            $input['nome'] ?? null,
            $input['curso'] ?? null,
            $input['periodo'] ?? null,
            $input['professor'] ?? null,
            $input['num_alunos'] ?? null,
            $input['carga_horaria'] ?? null,
            $input['horario_inicio'] ?? null,
            $input['horario_fim'] ?? null,
            $segunda,
            $terca,
            $quarta,
            $quinta,
            $sexta,
            $sabado,
            $input['turno'] ?? null,
            $input['observacoes'] ?? null,
            $ativo,
            $sala_fixa_id,
            $input['id']
        ]);
        
        echo json_encode([
            'success' => true,
            'data' => ['message' => 'Turma atualizada com sucesso']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao atualizar turma: ' . $e->getMessage()
        ]);
    }
}

function excluirTurma() {
    global $database;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        if (empty($input['id'])) {
            throw new Exception('ID da turma é obrigatório');
        }
        
        // Verificar se turma existe
        $stmt = $database->prepare("SELECT id FROM turmas WHERE id = ? AND ativo = 1");
        $stmt->execute([$input['id']]);
        
        if (!$stmt->fetch()) {
            throw new Exception('Turma não encontrada');
        }
        
        // Marcar como inativa (soft delete)
        $stmt = $database->prepare("UPDATE turmas SET ativo = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$input['id']]);
        
        echo json_encode([
            'success' => true,
            'data' => ['message' => 'Turma excluída com sucesso']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao excluir turma: ' . $e->getMessage()
        ]);
    }
}

