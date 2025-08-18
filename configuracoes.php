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
 * API Configurações
 * Gerencia configurações do sistema
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

// Adicione esta função antes do bloco "try...catch"

/**
 * Busca no banco de dados todos os períodos letivos únicos.
 * @param PDO $db A conexão com o banco de dados.
 * @return array A lista de períodos.
 */
function obterPeriodosExistentes($db) {
    // CORREÇÃO: Agora lê da nova tabela 'periodos'
    $sql = "SELECT periodo FROM periodos ORDER BY periodo DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Substitua a sua função excluirPeriodo por esta versão mais robusta

function excluirPeriodo($db, $periodo_a_excluir) {
    // 1. Verificar se existem turmas REAIS associadas a este período.
    $sql_check = "SELECT COUNT(*) FROM turmas WHERE periodo = ?";
    $stmt_check = $db->prepare($sql_check);
    $stmt_check->execute([$periodo_a_excluir]);
    
    // Se a contagem for maior que zero, não podemos excluir.
    if ($stmt_check->fetchColumn() > 0) {
        throw new Exception('Não é possível excluir este período, pois ele já possui turmas cadastradas.');
    }

    // 2. Se não há turmas, podemos excluir o período da tabela 'periodos'.
    $sql_delete_periodo = "DELETE FROM periodos WHERE periodo = ?";
    $stmt_delete_periodo = $db->prepare($sql_delete_periodo);
    $stmt_delete_periodo->execute([$periodo_a_excluir]);

    // 3. BÔNUS: Limpar a turma "placeholder" correspondente, se ela existir.
    //    Isso mantém a tabela 'turmas' limpa.
    $codigo_placeholder = 'PERIODO_PLACEHOLDER_' . $periodo_a_excluir;
    $sql_delete_placeholder = "DELETE FROM turmas WHERE codigo = ?";
    $stmt_delete_placeholder = $db->prepare($sql_delete_placeholder);
    $stmt_delete_placeholder->execute([$codigo_placeholder]);

    return true; // Retorna sucesso
}




try {
    $action = $_GET['action'] ?? '';
    
    // Inicializar conexão com banco
    global $database;
    
    switch ($action) {

// Dentro do seu switch ($action)

case 'criar_periodo':
    // Garante que o método da requisição seja POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // Lança uma exceção que será capturada pelo bloco catch
        throw new Exception('Método não permitido. Use POST.');
    }
    
    // Pega os dados enviados pelo JavaScript
    $dados = json_decode(file_get_contents('php://input'), true);
    $novo_periodo = $dados['periodo'] ?? '';

    if (empty($novo_periodo)) {
        throw new Exception('O campo período não pode estar vazio.');
    }

    // Tenta criar o período. A própria função pode lançar uma exceção.
    criarNovoPeriodo($database, $novo_periodo);
    
    // Se nenhuma exceção foi lançada, envia uma resposta de sucesso
    echo json_encode(['success' => true, 'message' => 'Período ' . htmlspecialchars($novo_periodo) . ' criado com sucesso!']);
    
    // Encerra o script
    exit;

        case 'excluir_periodo':
    // Garante que o método da requisição seja POST (mais seguro para ações de exclusão)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('Método não permitido. Use POST.');
             }
    
    // Pega os dados enviados pelo JavaScript
            $dados = json_decode(file_get_contents('php://input'), true);
            $periodo_a_excluir = $dados['periodo'] ?? '';

            if (empty($periodo_a_excluir)) {
            throw new Exception('O período a ser excluído não foi especificado.');
            }

    // Chama a função para excluir o período
            excluirPeriodo($database, $periodo_a_excluir);
    
    // Envia uma resposta de sucesso
            echo json_encode(['success' => true, 'message' => 'Período ' . htmlspecialchars($periodo_a_excluir) . ' excluído com sucesso!']);
            exit;

        case 'listar_periodos':
            $periodos = obterPeriodosExistentes($database);
            echo json_encode(['success' => true, 'data' => $periodos]);
            exit;

        case 'listar':
            listarConfiguracoes();
            break;
            
        case 'buscar':
            if (empty($_GET['chave'])) {
                throw new Exception('Chave da configuração é obrigatória');
            }
            buscarConfiguracao($_GET['chave']);
            break;
            
        case 'salvar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            salvarConfiguracao();
            break;
            
        case 'excluir':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            excluirConfiguracao();
            break;
            
        case 'resetar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            resetarConfiguracoes();
            break;
            
        case 'exportar':
            exportarConfiguracoes();
            break;
            
        case 'importar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            importarConfiguracoes();
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

function listarConfiguracoes() {
    global $database;
    
    try {
        $stmt = $database->prepare("
            SELECT 
                id,
                chave,
                valor,
                descricao,
                categoria,
                updated_at
            FROM configuracoes 
            ORDER BY categoria, chave
        ");
        
        $stmt->execute();
        $configuracoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Se não há configurações, criar as padrão
        if (empty($configuracoes)) {
            criarConfiguracoesPadrao();
            
            // Buscar novamente
            $stmt->execute();
            $configuracoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true,
            'data' => $configuracoes
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao listar configurações: ' . $e->getMessage()
        ]);
    }
}

function buscarConfiguracao($chave) {
    global $database;
    
    try {
        $stmt = $database->prepare("
            SELECT 
                id,
                chave,
                valor,
                descricao,
                categoria,
                updated_at
            FROM configuracoes 
            WHERE chave = ?
        ");
        
        $stmt->execute([$chave]);
        $configuracao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$configuracao) {
            throw new Exception('Configuração não encontrada');
        }
        
        echo json_encode([
            'success' => true,
            'data' => $configuracao
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao buscar configuração: ' . $e->getMessage()
        ]);
    }
}

function salvarConfiguracao() {
    global $database;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        // Validações
        if (empty($input['chave'])) {
            throw new Exception('Chave da configuração é obrigatória');
        }
        
        if (!isset($input['valor'])) {
            throw new Exception('Valor da configuração é obrigatório');
        }
        
        // Verificar se configuração já existe
        $stmt = $database->prepare("SELECT id FROM configuracoes WHERE chave = ?");
        $stmt->execute([$input['chave']]);
        $existe = $stmt->fetch();
        
        if ($existe) {
            // Atualizar configuração existente
            $stmt = $database->prepare("
                UPDATE configuracoes SET 
                    valor = ?,
                    descricao = COALESCE(?, descricao),
                    categoria = COALESCE(?, categoria),
                    updated_at = CURRENT_TIMESTAMP
                WHERE chave = ?
            ");
            
            $stmt->execute([
                $input['valor'],
                $input['descricao'] ?? null,
                $input['categoria'] ?? null,
                $input['chave']
            ]);
            
            $message = 'Configuração atualizada com sucesso';
        } else {
            // Criar nova configuração
            $stmt = $database->prepare("
                INSERT INTO configuracoes (chave, valor, descricao, categoria) 
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $input['chave'],
                $input['valor'],
                $input['descricao'] ?? '',
                $input['categoria'] ?? 'geral'
            ]);
            
            $message = 'Configuração criada com sucesso';
        }
        
        echo json_encode([
            'success' => true,
            'data' => ['message' => $message]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao salvar configuração: ' . $e->getMessage()
        ]);
    }
}

function excluirConfiguracao() {
    global $database;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        if (empty($input['chave'])) {
            throw new Exception('Chave da configuração é obrigatória');
        }
        
        // Verificar se configuração existe
        $stmt = $database->prepare("SELECT id FROM configuracoes WHERE chave = ?");
        $stmt->execute([$input['chave']]);
        
        if (!$stmt->fetch()) {
            throw new Exception('Configuração não encontrada');
        }
        
        // Excluir configuração
        $stmt = $database->prepare("DELETE FROM configuracoes WHERE chave = ?");
        $stmt->execute([$input['chave']]);
        
        echo json_encode([
            'success' => true,
            'data' => ['message' => 'Configuração excluída com sucesso']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao excluir configuração: ' . $e->getMessage()
        ]);
    }
}

function resetarConfiguracoes() {
    global $database;
    
    try {
        // Limpar configurações existentes
        $database->exec("DELETE FROM configuracoes");
        
        // Criar configurações padrão
        criarConfiguracoesPadrao();
        
        echo json_encode([
            'success' => true,
            'data' => ['message' => 'Configurações resetadas para os valores padrão']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao resetar configurações: ' . $e->getMessage()
        ]);
    }
}

function exportarConfiguracoes() {
    global $database;
    
    try {
        $stmt = $database->prepare("SELECT chave, valor, descricao, categoria FROM configuracoes ORDER BY categoria, chave");
        $stmt->execute();
        $configuracoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $export = [
            'sistema' => 'Sistema de Ensalamento',
            'versao' => '1.0.0',
            'data_export' => date('Y-m-d H:i:s'),
            'configuracoes' => $configuracoes
        ];
        
        echo json_encode([
            'success' => true,
            'data' => $export
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao exportar configurações: ' . $e->getMessage()
        ]);
    }
}

function importarConfiguracoes() {
    global $database;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        if (empty($input['configuracoes'])) {
            throw new Exception('Dados de configurações são obrigatórios');
        }
        
        $configuracoes = $input['configuracoes'];
        $importadas = 0;
        
        foreach ($configuracoes as $config) {
            if (empty($config['chave']) || !isset($config['valor'])) {
                continue;
            }
            
            // Inserir ou atualizar configuração
            $stmt = $database->prepare("
                INSERT INTO configuracoes (chave, valor, descricao, categoria) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    valor = VALUES(valor),
                    descricao = VALUES(descricao),
                    categoria = VALUES(categoria),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $config['chave'],
                $config['valor'],
                $config['descricao'] ?? '',
                $config['categoria'] ?? 'geral'
            ]);
            
            $importadas++;
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'message' => "Configurações importadas com sucesso",
                'total_importadas' => $importadas
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao importar configurações: ' . $e->getMessage()
        ]);
    }
}

function criarConfiguracoesPadrao() {
    global $database;
    
    $configuracoes_padrao = [
        // Configurações Gerais
        ['periodo_atual', '2025.1', 'Período letivo atual', 'geral'],
        ['algoritmo_padrao', 'otimizado', 'Algoritmo padrão para ensalamento', 'ensalamento'],
        ['backup_automatico', '1', 'Ativar backup automático', 'sistema'],
        ['logs_habilitados', '1', 'Ativar logs do sistema', 'sistema'],
        
        // Configurações de Ensalamento
        ['max_conflitos', '5', 'Máximo de conflitos permitidos', 'ensalamento'],
        ['eficiencia_minima', '80', 'Eficiência mínima esperada (%)', 'ensalamento'],
        ['timeout_execucao', '300', 'Timeout para execução (segundos)', 'ensalamento'],
        
        // Configurações de Interface
        ['tema', 'claro', 'Tema da interface', 'interface'],
        ['itens_por_pagina', '20', 'Itens por página nas listagens', 'interface'],
        ['auto_refresh', '30', 'Auto-refresh do dashboard (segundos)', 'interface'],
        
        // Configurações de Notificações
        ['notif_email', '1', 'Notificações por e-mail', 'notificacoes'],
        ['notif_conflitos', '1', 'Notificar conflitos de ensalamento', 'notificacoes'],
        ['notif_backup', '1', 'Notificar backups realizados', 'notificacoes']
    ];
    
    $stmt = $database->prepare("
        INSERT INTO configuracoes (chave, valor, descricao, categoria) 
        VALUES (?, ?, ?, ?)
    ");
    
    foreach ($configuracoes_padrao as $config) {
        $stmt->execute($config);
    }
}

// Substitua a sua função criarNovoPeriodo inteira por esta versão

// Em api/configuracoes_api.php
function criarNovoPeriodo($db, $novo_periodo) {
    if (!preg_match('/^\d{4}\.[1-2]$/', $novo_periodo)) {
        throw new Exception('Formato do período inválido.');
    }

    // CORREÇÃO: A lógica agora é muito mais simples e direta.
    $sql_insert = "INSERT INTO periodos (periodo) VALUES (?)";
    $stmt_insert = $db->prepare($sql_insert);

    try {
        $stmt_insert->execute([$novo_periodo]);
    } catch (PDOException $e) {
        // Captura o erro de violação de chave única (se o período já existir)
        if ($e->getCode() == '23000') { 
            throw new Exception('Este período já existe no sistema.');
        }
        throw $e; // Lança outros erros
    }
    return true;
}



?>

