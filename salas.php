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
 * API Salas
 * Gerencia operações CRUD de salas
 */


try {
    require_once __DIR__ . '/../config/database.php';
    
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Verificar se a conexão com banco está funcionando
    if (!isset($database)) {
        throw new Exception('Conexão com banco não disponível');
    }
    
    switch ($action) {
        case 'listar':
            $response = listarSalas($database);
            break;

        case 'listar_simples':
        // Esta ação é otimizada para preencher menus dropdown (selects).
        // Ela retorna apenas o ID, código e nome, o que é mais rápido.
        $stmt = $database->prepare("SELECT id, nome, codigo FROM salas WHERE ativo = 1 ORDER BY codigo");
        $stmt->execute();
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break; // break é importante para parar a execução aqui.  

        case 'listar_recursos':
            $recursos = listarRecursos($database);
            echo json_encode(['success' => true, 'data' => $recursos]);
            exit;
            
        case 'buscar':
            if (empty($_GET['id'])) {
                throw new Exception('ID da sala é obrigatório');
            }
            $response = buscarSala($database, $_GET['id']);
            break;
            
        case 'criar':
            if ($method !== 'POST') {
                throw new Exception('Método não permitido');
            }
            $response = criarSala($database);
            break;
            
        case 'atualizar':
            if ($method !== 'POST') {
                throw new Exception('Método não permitido');
            }
            $response = atualizarSala($database);
            break;
            
        case 'excluir':
            if ($method !== 'POST') {
                throw new Exception('Método não permitido');
            }
            if (empty($_POST['id'])) {
                throw new Exception('ID da sala é obrigatório');
            }
            $response = excluirSala($database, $_POST['id']);
            break;
            
        case 'tipos':
            $response = obterTiposSala();
            break;
            
        case 'estatisticas':
            $response = obterEstatisticasSalas($database);
            break;
            
        default:
            throw new Exception('Ação não reconhecida');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $response
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function listarSalas($database) {
    try {
        unset($_GET['status']);

        $filtros = [];
        $params = [];
        $where_clauses = [];
        
        // Filtro por tipo
        if (!empty($_GET['tipo'])) {
            $where_clauses[] = "tipo = ?";
            $params[] = $_GET['tipo'];
        }
        
        // Filtro por status
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $where_clauses[] = "ativo = ?";
            $params[] = (int)$_GET['status'];
        }
        
        // Filtro por capacidade mínima
        if (!empty($_GET['capacidade_min'])) {
            $where_clauses[] = "capacidade >= ?";
            $params[] = (int)$_GET['capacidade_min'];
        }
        
        // Construir WHERE
        $where = '';
        if (!empty($where_clauses)) {
            $where = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // Paginação
        $limite = (int)($_GET['limite'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $sql = "SELECT * FROM salas $where ORDER BY codigo LIMIT $limite OFFSET $offset";
        
        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        $salas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar total
        $sql_count = "SELECT COUNT(*) as total FROM salas $where";
        $stmt_count = $database->prepare($sql_count);
        $stmt_count->execute($params);
        $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
        
        return [
            'salas' => $salas,
            'total' => $total,
            'limite' => $limite,
            'offset' => $offset
        ];
    } catch (Exception $e) {
        throw new Exception('Erro ao listar salas: ' . $e->getMessage());
    }
}

function buscarSala($database, $id) {
    try {
        $stmt = $database->prepare("SELECT * FROM salas WHERE id = ?");
        $stmt->execute([$id]);
        $sala = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sala) {
            throw new Exception('Sala não encontrada');
        }
        
        return $sala;
    } catch (Exception $e) {
        throw new Exception('Erro ao buscar sala: ' . $e->getMessage());
    }
}

function criarSala($database) {
    try {
        // Processar dados do formulário
        $codigo = $_POST['codigo'] ?? '';
        $nome = $_POST['nome'] ?? '';
        $tipo = $_POST['tipo'] ?? 'comum';
        $capacidade = (int)($_POST['capacidade'] ?? 0);
        $descricao = $_POST['descricao'] ?? '';
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        // Validações
        if (empty($codigo)) {
            throw new Exception('Código da sala é obrigatório');
        }
        
        if (empty($nome)) {
            throw new Exception('Nome da sala é obrigatório');
        }
        
        if ($capacidade <= 0) {
            throw new Exception('Capacidade deve ser maior que zero');
        }
        
        // Verificar se código já existe
        $stmt = $database->prepare("SELECT id FROM salas WHERE codigo = ?");
        $stmt->execute([$codigo]);
        if ($stmt->fetch()) {
            throw new Exception('Código da sala já existe');
        }
        
        // Inserir sala
        $stmt = $database->prepare("
            INSERT INTO salas (codigo, nome, tipo, capacidade, descricao, ativo, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $codigo,
            $nome,
            $tipo,
            $capacidade,
            $descricao,
            $ativo
        ]);
        
        $id = $database->lastInsertId();
        
        return [
            'id' => $id,
            'message' => 'Sala criada com sucesso'
        ];
    } catch (Exception $e) {
        throw new Exception('Erro ao criar sala: ' . $e->getMessage());
    }
}

function listarTodasAsSalas($database) {
    $stmt = $database->prepare("SELECT * FROM salas ORDER BY codigo ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Em api/salas.php

function atualizarSala($database) {
    try {
        // --- LEITURA DOS DADOS ---
        $id = $_POST['id'] ?? null;
        $codigo = $_POST['codigo'] ?? '';
        $nome = $_POST['nome'] ?? '';
        $tipo = $_POST['tipo'] ?? 'comum';
        $capacidade = (int)($_POST['capacidade'] ?? 0);
        $localizacao = $_POST['localizacao'] ?? '';
        $recursos = $_POST['recursos'] ?? '';
        
        // ==========================================================
        // >> LÓGICA CORRIGIDA E SIMPLIFICADA PARA O CHECKBOX <<
        // ==========================================================
        // O seu JavaScript já envia '1' ou '0'.
        // Nós apenas garantimos que ele seja um inteiro.
        $ativo = (int)($_POST['ativo'] ?? 0);
        // ==========================================================

        // --- VALIDAÇÕES ---
        if (!$id) {
            throw new Exception('ID da sala é obrigatório para atualização.');
        }
        
        // --- PREPARAÇÃO E EXECUÇÃO DA CONSULTA SQL ---
        $stmt = $database->prepare("
            UPDATE salas SET 
                codigo = :codigo, 
                nome = :nome, 
                tipo = :tipo, 
                capacidade = :capacidade, 
                localizacao = :localizacao,
                recursos = :recursos,
                ativo = :ativo,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        // --- EXECUÇÃO COM PARÂMETROS NOMEADOS (Mais seguro) ---
        $stmt->execute([
            ':codigo' => $codigo,
            ':nome' => $nome,
            ':tipo' => $tipo,
            ':capacidade' => $capacidade,
            ':localizacao' => $localizacao,
            ':recursos' => $recursos,
            ':ativo' => $ativo, // Passa o valor 0 ou 1
            ':id' => $id
        ]);
        
        // --- RESPOSTA DE SUCESSO ---
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Sala atualizada com sucesso!'
        ]);
        exit;

    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500 );
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao atualizar sala: ' . $e->getMessage()
        ]);
        exit;
    }
}




function listarRecursos($db) {
    // Assumindo que você tem uma tabela 'recursos' com colunas 'id' e 'nome'
    $sql = "SELECT id, nome FROM recursos ORDER BY nome ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function excluirSala($database, $id) {
    try {
        // Verificar se sala existe
        $stmt = $database->prepare("SELECT id FROM salas WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception('Sala não encontrada');
        }
        
        // Verificar se sala está sendo usada
        $stmt = $database->prepare("SELECT COUNT(*) as total FROM ensalamento WHERE sala_id = ?");
        $stmt->execute([$id]);
        $uso = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($uso['total'] > 0) {
            throw new Exception('Não é possível excluir sala que possui ensalamentos');
        }
        
        // Excluir sala
        $stmt = $database->prepare("DELETE FROM salas WHERE id = ?");
        $stmt->execute([$id]);
        
        return [
            'message' => 'Sala excluída com sucesso'
        ];
    } catch (Exception $e) {
        throw new Exception('Erro ao excluir sala: ' . $e->getMessage());
    }
}

function obterTiposSala() {
    return [
        'comum' => 'Sala Comum',
        'laboratorio' => 'Laboratório',
        'informatica' => 'Informática',
        'auditorio' => 'Auditório',
        'pratica' => 'Sala Prática'
    ];
}

function obterEstatisticasSalas($database) {
    try {
        // Total de salas
        $stmt = $database->prepare("SELECT COUNT(*) as total FROM salas");
        $stmt->execute();
        $total_salas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Salas ativas
        $stmt = $database->prepare("SELECT COUNT(*) as total FROM salas WHERE ativo = 1");
        $stmt->execute();
        $salas_ativas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Capacidade total
        $stmt = $database->prepare("SELECT SUM(capacidade) as total FROM salas WHERE ativo = 1");
        $stmt->execute();
        $capacidade_total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        // Salas por tipo
        $stmt = $database->prepare("
            SELECT tipo, COUNT(*) as quantidade 
            FROM salas 
            WHERE ativo = 1 
            GROUP BY tipo
        ");
        $stmt->execute();
        $salas_por_tipo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'total_salas' => $total_salas,
            'salas_ativas' => $salas_ativas,
            'capacidade_total' => $capacidade_total,
            'salas_por_tipo' => $salas_por_tipo
        ];
    } catch (Exception $e) {
        throw new Exception('Erro ao obter estatísticas: ' . $e->getMessage());
    }
}
?>

