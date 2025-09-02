<?php
// Desabilitar TODOS os erros PHP para garantir JSON válido
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Capturar qualquer erro e retornar JSON
set_error_handler(function($severity, $message, $file, $line) {
    return true;
});

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php'; 
use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Verificar se a conexão com banco está funcionando
    if (!isset($database)) {
        throw new Exception('Conexão com banco não disponível');
    }
    
$response = null; 

    switch ($action) {
        case 'listar':
            $response = listarSalas($database);
            break;

        case 'listar_simples':
        $stmt = $database->prepare("SELECT id, nome, codigo FROM salas WHERE ativo = 1 ORDER BY codigo");
        $stmt->execute();
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;  

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

        case 'importar':
            $resultado = importarSalas($database);
            $response = $resultado;
            break;

        case 'exportar':
            // 1. Incluir as classes necessárias da PhpSpreadsheet
            // (O autoload já deve cuidar disso, mas é bom ter os 'use' statements)
            // use PhpOffice\PhpSpreadsheet\Spreadsheet;
            // use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

            // 2. Criar um novo objeto de planilha
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Lista de Salas');

            // 3. Definir o cabeçalho da planilha
            $sheet->setCellValue('A1', 'ID');
            $sheet->setCellValue('B1', 'Código');
            $sheet->setCellValue('C1', 'Nome');
            $sheet->setCellValue('D1', 'Prédio');
            $sheet->setCellValue('E1', 'Bloco');
            $sheet->setCellValue('F1', 'Capacidade');
            $sheet->setCellValue('G1', 'Tipo');
            $sheet->setCellValue('H1', 'Localização');
            $sheet->setCellValue('I1', 'Recursos');
            $sheet->setCellValue('J1', 'Status');

            // (Opcional) Estilizar o cabeçalho (negrito)
            $sheet->getStyle('A1:J1')->getFont()->setBold(true);

            // 4. Buscar os dados do banco de dados
            $stmt = $database->query("SELECT id, codigo, nome, predio, bloco, capacidade, tipo, localizacao, recursos, ativo FROM salas ORDER BY codigo");
            
            // 5. Preencher a planilha com os dados, começando da linha 2
            $row = 2;
            while ($sala = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sheet->setCellValue('A' . $row, $sala['id']);
                $sheet->setCellValue('B' . $row, $sala['codigo']);
                $sheet->setCellValue('C' . $row, $sala['nome']);
                $sheet->setCellValue('D' . $row, $sala['predio']);
                $sheet->setCellValue('E' . $row, $sala['bloco']);
                $sheet->setCellValue('F' . $row, $sala['capacidade']);
                $sheet->setCellValue('G' . $row, $sala['tipo']);
                $sheet->setCellValue('H' . $row, $sala['localizacao']);
                $sheet->setCellValue('I' . $row, $sala['recursos']);
                // Converte o status '1'/'0' para 'Ativa'/'Inativa'
                $sheet->setCellValue('J' . $row, ($sala['ativo'] == 1 ? 'Ativa' : 'Inativa'));
                $row++;
            }

            // (Opcional) Ajustar a largura das colunas automaticamente
            foreach (range('A', 'J') as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }

            // 6. Preparar para o download
            $filename = "salas_" . date('Y-m-d') . ".xlsx";

            // Definir os cabeçalhos HTTP para forçar o download do arquivo XLSX
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            // 7. Criar o "escritor" e salvar a saída no fluxo do PHP
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');

            // 8. Interromper a execução do script
            exit;


        case 'excluir':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('Método não permitido');
            }
            if (empty($_POST['id'])) {
            throw new Exception('ID da sala é obrigatório para exclusão');
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
        
        if (!empty($_GET['tipo'])) {
            $where_clauses[] = "tipo = ?";
            $params[] = $_GET['tipo'];
        }
        
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $where_clauses[] = "ativo = ?";
            $params[] = (int)$_GET['status'];
        }
        
        if (!empty($_GET['capacidade_min'])) {
            $where_clauses[] = "capacidade >= ?";
            $params[] = (int)$_GET['capacidade_min'];
        }
        
        $where = '';
        if (!empty($where_clauses)) {
            $where = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        $limite = (int)($_GET['limite'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $sql = "SELECT * FROM salas $where ORDER BY codigo LIMIT $limite OFFSET $offset";
        
        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        $salas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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

function importarSalas($database) {
    if (!isset($_FILES['arquivo_salas']) || $_FILES['arquivo_salas']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Nenhum arquivo enviado ou erro no upload.');
    }

    $caminhoArquivo = $_FILES['arquivo_salas']['tmp_name'];
    $extensao = strtolower(pathinfo($_FILES['arquivo_salas']['name'], PATHINFO_EXTENSION));

    try {
        $spreadsheet = IOFactory::load($caminhoArquivo);
        $sheet = $spreadsheet->getActiveSheet();
        $dados = $sheet->toArray(null, true, true, true);
        array_shift($dados);
    } catch (Exception $e) {
        throw new Exception('Erro ao ler o arquivo. Verifique se o formato é válido (.xlsx ou .csv).');
    }

    $sucessos = 0;
    $erros = 0;
    $mensagens_erro = [];

    $stmt_verificar = $database->prepare("SELECT id FROM salas WHERE codigo = ?");
    $stmt_inserir = $database->prepare(
        "INSERT INTO salas (codigo, nome, predio, bloco, capacidade, tipo, localizacao, recursos, ativo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())"
    );

    foreach ($dados as $linhaNumero => $linha) {
        $codigo = trim($linha['A']);
        $nome = trim($linha['B']);
        $predio = trim($linha['C']);
        $bloco = trim($linha['D']);
        $capacidade = (int)trim($linha['E']);
        $tipo = trim($linha['F']);
        $localizacao = trim($linha['G']);
        $recursos = trim($linha['H']);

        // Validações básicas
        if (empty($codigo) || empty($nome) || $capacidade <= 0) {
            $erros++;
            $mensagens_erro[] = "Linha " . ($linhaNumero + 1) . ": Dados inválidos ou faltando (código, nome e capacidade são obrigatórios).";
            continue;
        }

        try {
            $stmt_verificar->execute([$codigo]);
            if ($stmt_verificar->fetch()) {
                $erros++;
                $mensagens_erro[] = "Linha " . ($linhaNumero + 1) . ": Sala com código '{$codigo}' já existe.";
                continue;
            }

            // Se não existe, insere no banco de dados
            $stmt_inserir->execute([
                $codigo,
                $nome,
                $predio,
                $bloco,
                $capacidade,
                $tipo,
                $localizacao, // << Adicionado
                $recursos     // << Adicionado
            ]);
            $sucessos++;

        } catch (Exception $e) {
            $erros++;
            $mensagens_erro[] = "Linha " . ($linhaNumero + 1) . ": Erro de banco de dados ao processar o código '{$codigo}'.";
        }
    }

    // Retorna um resumo da operação
    return [
        'total_linhas' => count($dados),
        'sucessos' => $sucessos,
        'erros' => $erros,
        'mensagens_erro' => $mensagens_erro
    ];
}

function criarSala($database) {
    try {
        // Processar dados do formulário
        $codigo = $_POST['codigo'] ?? '';
        $nome = $_POST['nome'] ?? '';
        $predio = $_POST['predio'] ?? ''; // <<-- ADIÇÃO 1
        $bloco = $_POST['bloco'] ?? '';   // <<-- ADIÇÃO 2
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
            INSERT INTO salas (codigo, nome, predio, bloco, tipo, capacidade, descricao, ativo, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $codigo,
            $nome,
            $predio, // <<-- ADIÇÃO 3
            $bloco,  // <<-- ADIÇÃO 4
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
        $predio = $_POST['predio'] ?? ''; // <<-- ADIÇÃO 1
        $bloco = $_POST['bloco'] ?? '';   // <<-- ADIÇÃO 2
        $tipo = $_POST['tipo'] ?? 'comum';
        $capacidade = (int)($_POST['capacidade'] ?? 0);
        $localizacao = $_POST['localizacao'] ?? '';
        $recursos = $_POST['recursos'] ?? '';
        
        $ativo = (int)($_POST['ativo'] ?? 0);

        if (!$id) {
            throw new Exception('ID da sala é obrigatório para atualização.');
        }
        
        // --- PREPARAÇÃO E EXECUÇÃO DA CONSULTA SQL ---
        $stmt = $database->prepare("
            UPDATE salas SET 
                codigo = :codigo, 
                nome = :nome, 
                predio = :predio,
                bloco = :bloco,
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
            ':predio' => $predio, // <<-- ADIÇÃO 3
            ':bloco' => $bloco,   // <<-- ADIÇÃO 4
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
        // 1. Verifica se a sala existe. Ótimo para evitar erros.
        $stmt = $database->prepare("SELECT id FROM salas WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception('Sala não encontrada');
        }
        
        // 2. Verifica se a sala está sendo usada em 'ensalamento'.
        //    O nome da coluna aqui é 'sala_id'.
        $stmt = $database->prepare("SELECT COUNT(*) as total FROM ensalamento WHERE sala_id = ?");
        $stmt->execute([$id]);
        $uso = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 3. AQUI ESTÁ A REGRA DE NEGÓCIO QUE GEROU O ERRO
        //    Se a contagem de ensalamentos para essa sala for maior que 0...
        if ($uso['total'] > 0) {
            // ...ele lança a exceção com a mensagem exata que você viu.
            throw new Exception('Não é possível excluir sala que possui ensalamentos');
        }
        
        // 4. Se a sala não estiver em uso, ela é excluída.
        $stmt = $database->prepare("DELETE FROM salas WHERE id = ?");
        $stmt->execute([$id]);
        
        // 5. Retorna a mensagem de sucesso.
        return [
            'message' => 'Sala excluída com sucesso'
        ];
    } catch (Exception $e) {
        // 6. O catch final reenvolve a mensagem de erro para o frontend.
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