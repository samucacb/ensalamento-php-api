<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line) {
    return true;
});

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    $action = $_GET['action'] ?? '';
    
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

// Em api/turmas.php

case 'exportar':
    // 1. Criar a planilha
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Lista de Turmas');

    // 2. Definir o CABEÇALHO COMPLETO, com a nova coluna "Status Alocação"
    $headers = [
        'A1' => 'ID',
        'B1' => 'Código da Turma',
        'C1' => 'Nome da Disciplina',
        'D1' => 'Curso',
        'E1' => 'Professor',
        'F1' => 'Nº de Alunos',
        'G1' => 'Período',
        'H1' => 'Turno',
        'I1' => 'Tipo de Aula',
        'J1' => 'Carga Horária',
        'K1' => 'Horário Início',
        'L1' => 'Horário Fim',
        'M1' => 'Dias da Semana',
        'N1' => 'Observações',
        'O1' => 'Status da Turma', // Ativa / Inativa
        'P1' => 'Status Alocação'  // << NOVA COLUNA
    ];
    foreach ($headers as $cell => $value) {
        $sheet->setCellValue($cell, $value);
    }
    // Estilizar o cabeçalho (agora até a coluna P)
    $sheet->getStyle('A1:P1')->getFont()->setBold(true);

    // 3. Criar a CONSULTA SQL APRIMORADA com LEFT JOIN
    // Esta consulta junta 'turmas' com 'ensalamento' para contar as alocações
    $sql = "
        SELECT 
            t.id, t.codigo, t.nome, t.curso, t.professor, t.num_alunos, t.periodo, t.turno, 
            t.tipo_aula, t.carga_horaria, t.horario_inicio, t.horario_fim, 
            t.segunda, t.terca, t.quarta, t.quinta, t.sexta, t.sabado, 
            t.observacoes, t.ativo,
            COUNT(e.id) as total_alocacoes -- << CONTA QUANTAS VEZES A TURMA APARECE EM 'ensalamento'
        FROM 
            turmas as t
        LEFT JOIN 
            ensalamento as e ON t.id = e.turma_id -- << FAZ A JUNÇÃO PELA CHAVE ESTRANGEIRA
        GROUP BY 
            t.id -- Agrupa os resultados por turma para a contagem funcionar
        ORDER BY 
            t.curso, t.nome
    ";
    $stmt = $database->query($sql);
    
    // 4. Preencher a planilha com os dados
    $row = 2;
    while ($turma = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Lógica para os dias da semana (existente)
        $dias = [];
        if ($turma['segunda']) $dias[] = 'Seg';
        if ($turma['terca'])   $dias[] = 'Ter';
        if ($turma['quarta'])  $dias[] = 'Qua';
        if ($turma['quinta'])  $dias[] = 'Qui';
        if ($turma['sexta'])   $dias[] = 'Sex';
        if ($turma['sabado'])  $dias[] = 'Sáb';
        $diasString = implode(', ', $dias);

        // Preencher as células da linha
        $sheet->setCellValue('A' . $row, $turma['id']);
        $sheet->setCellValue('B' . $row, $turma['codigo']);
        $sheet->setCellValue('C' . $row, $turma['nome']);
        $sheet->setCellValue('D' . $row, $turma['curso']);
        $sheet->setCellValue('E' . $row, $turma['professor']);
        $sheet->setCellValue('F' . $row, $turma['num_alunos']);
        $sheet->setCellValue('G' . $row, $turma['periodo']);
        $sheet->setCellValue('H' . $row, $turma['turno']);
        $sheet->setCellValue('I' . $row, $turma['tipo_aula']);
        $sheet->setCellValue('J' . $row, $turma['carga_horaria']);
        $sheet->setCellValue('K' . $row, $turma['horario_inicio']);
        $sheet->setCellValue('L' . $row, $turma['horario_fim']);
        $sheet->setCellValue('M' . $row, $diasString);
        $sheet->setCellValue('N' . $row, $turma['observacoes']);
        $sheet->setCellValue('O' . $row, ($turma['ativo'] == 1 ? 'Ativa' : 'Inativa'));
        
        // >> LÓGICA DO NOVO STATUS DE ALOCAÇÃO <<
        $statusAlocacao = ($turma['total_alocacoes'] > 0) ? 'Alocado' : 'Pendente';
        $sheet->setCellValue('P' . $row, $statusAlocacao); // Preenche a nova coluna P
        
        $row++;
    }

    // Ajustar a largura de todas as colunas (agora até P)
    foreach (range('A', 'P') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // 5. Preparar e enviar o arquivo para download
    $filename = "turmas_completo_" . date('Y-m-d') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
    exit;

    case 'importar':
            // Verifica se um arquivo foi enviado corretamente
            if (!isset($_FILES['arquivo_turmas']) || $_FILES['arquivo_turmas']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Nenhum arquivo enviado ou erro no upload.');
            }

            $caminhoArquivo = $_FILES['arquivo_turmas']['tmp_name'];

            // Carrega o arquivo usando a PhpSpreadsheet
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($caminhoArquivo);
                $sheet = $spreadsheet->getActiveSheet();
                $dados = $sheet->toArray(null, true, true, true);
                array_shift($dados); // Remove a linha de cabeçalho
            } catch (Exception $e) {
                throw new Exception('Erro ao ler o arquivo. Verifique se o formato é .xlsx e se não está corrompido.');
            }

            $sucessos = 0;
            $erros = 0;
            $mensagens_erro = [];

            // Prepara as consultas para otimização
            $stmt_verificar = $database->prepare("SELECT id FROM turmas WHERE codigo = ?");
            $sql_inserir = "INSERT INTO turmas (turma, codigo, nome, curso, professor, num_alunos, periodo, turno, tipo_aula, carga_horaria, horario_inicio, horario_fim, segunda, terca, quarta, quinta, sexta, sabado, observacoes, ativo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            $stmt_inserir = $database->prepare($sql_inserir);

            foreach ($dados as $linhaNumero => $linha) {
                // Mapeia as colunas do Excel para variáveis (A=0, B=1, etc.)
             $turma_data = [
                 'turma' => trim($linha['A']),
                 'codigo' => trim($linha['B']),
                 'nome' => trim($linha['C']),
                 'curso' => trim($linha['D']),
                 'professor' => trim($linha['E']),
                 'num_alunos' => (int)trim($linha['F']),
                 'periodo' => trim($linha['G']),
                 'turno' => trim($linha['H']),
                 'tipo_aula' => trim($linha['I']),
                 'carga_horaria' => trim($linha['J']),
                 'horario_inicio' => trim($linha['K']),
                 'horario_fim' => trim($linha['L']),
                 'segunda' => (int)trim($linha['M']), // Converte para 0 ou 1
                 'terca' => (int)trim($linha['N']),
                 'quarta' => (int)trim($linha['O']),
                 'quinta' => (int)trim($linha['P']),
                 'sexta' => (int)trim($linha['Q']),
                 'sabado' => (int)trim($linha['R']),
                 'observacoes' => trim($linha['S']),
             ];

                // Validações básicas
                if (empty($turma_data['codigo']) || empty($turma_data['nome']) || $turma_data['num_alunos'] <= 0) {
                    $erros++;
                    $mensagens_erro[] = "Linha " . ($linhaNumero) . ": Dados inválidos. Código, Nome e Nº de Alunos são obrigatórios.";
                    continue;
                }

                try {
                    // Verifica se a turma já existe
                    $stmt_verificar->execute([$turma_data['codigo']]);
                    if ($stmt_verificar->fetch()) {
                        $erros++;
                        $mensagens_erro[] = "Linha " . ($linhaNumero) . ": Turma com código '{$turma_data['codigo']}' já existe.";
                        continue;
                    }

                    // Insere no banco de dados
                    $stmt_inserir->execute(array_values($turma_data));
                    $sucessos++;

                } catch (Exception $e) {
                    $erros++;
                    $mensagens_erro[] = "Linha " . ($linhaNumero) . ": Erro de banco de dados ao processar o código '{$turma_data['codigo']}'.";
                }
            }

            // Retorna um resumo da operação
            $resultado = [
                'total_linhas' => count($dados),
                'sucessos' => $sucessos,
                'erros' => $erros,
                'mensagens_erro' => $mensagens_erro
            ];
            
            // Envia a resposta JSON e termina o script
            echo json_encode(['success' => true, 'data' => $resultado]);
    exit;
            
case 'excluir':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception('Método não permitido');
    }
    
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('ID da turma inválido ou não fornecido.');
    }

    $stmt = $database->prepare("DELETE FROM turmas WHERE id = ?");
    $sucesso = $stmt->execute([$id]);

    if (!$sucesso) {
        throw new Exception('A exclusão falhou no banco de dados.');
    }

    echo json_encode(['success' => true, 'message' => 'Turma excluída com sucesso.']);
   
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

// Substitua a função inteira por esta versão melhorada
function listarTurmas() {
    global $database;
    
    try {
        // Pega os filtros da requisição
        $filtros = [
            'periodo' => $_GET['periodo'] ?? '', // <-- MUDANÇA 1: O padrão agora é VAZIO
            'curso' => $_GET['curso'] ?? '',
            'professor' => $_GET['professor'] ?? '',
            'busca' => $_GET['busca'] ?? ''
        ];
        
        $pagina = (int)($_GET['pagina'] ?? 1);
        $limite = (int)($_GET['limite'] ?? 99999);
        
        // A base da consulta é a mesma
        $sql_base = "FROM turmas t LEFT JOIN ensalamento e ON t.id = e.turma_id";
        
        $where = ['1=1'];
        $params = [];
        
        // ==========================================================
        // LÓGICA DE FILTRO MELHORADA
        // ==========================================================
        if (!empty($filtros['periodo'])) {
            // Se um período foi especificado, adiciona o filtro ao WHERE e ao JOIN
            $sql_base .= " AND e.periodo = :periodo_join"; // Adiciona ao JOIN
            $where[] = 't.periodo = :periodo_where';      // Adiciona ao WHERE
            $params[':periodo_join'] = $filtros['periodo'];
            $params[':periodo_where'] = $filtros['periodo'];
        }
        // Se nenhum período for especificado, o JOIN e o WHERE não filtram por período,
        // trazendo todos os resultados corretamente.
        // ==========================================================

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
        
        // A consulta SELECT principal continua a mesma
        $sql = "SELECT 
                    t.*,
                    MAX(e.status) as status_ensalamento,
                    -- =================================================================
                    -- >> CORREÇÃO FINAL AQUI: CONSTRUIR OS DIAS A PARTIR DA TABELA 'turmas' (t) <<
                    -- =================================================================
                    CONCAT_WS(', ',
                        IF(t.segunda, 'Segunda', NULL),
                        IF(t.terca, 'Terça', NULL),
                        IF(t.quarta, 'Quarta', NULL),
                        IF(t.quinta, 'Quinta', NULL),
                        IF(t.sexta, 'Sexta', NULL),
                        IF(t.sabado, 'Sábado', NULL)
                    ) as dias_semana_formatado,
                    t.horario_inicio,
                    t.horario_fim
                " . $sql_base . " 
                WHERE " . implode(' AND ', $where) . " 
                GROUP BY t.id 
                ORDER BY t.codigo 
                LIMIT " . $limite . " OFFSET " . $offset;

        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        $turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // A consulta para contar o total também é ajustada
        $sql_count = "SELECT COUNT(DISTINCT t.id) as total " . $sql_base . " WHERE " . implode(' AND ', $where);
        $stmt_count = $database->prepare($sql_count);
        // Remove o parâmetro do join se ele não for necessário para a contagem
        if (isset($params[':periodo_join']) && !isset($params[':periodo_where'])) {
            unset($params[':periodo_join']);
        }
        $stmt_count->execute($params);
        $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
        
        // A resposta JSON continua a mesma
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
        /*$periodo = $_GET['periodo'] ?? '2025.1';*/
        
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
                turma,
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
        
        // Código duplicado agora é permitido - comentado
         $stmt = $database->prepare("SELECT id FROM turmas WHERE codigo = ? AND ativo = 1");
         $stmt->execute([$input['codigo']]);
         if ($stmt->fetch()) {
             throw new Exception('Já existe uma turma com este código');
         }
        
        // Inserir turma
$stmt = $database->prepare("
    INSERT INTO turmas (
        turma, codigo, nome, curso, periodo, professor, num_alunos, tipo_aula, carga_horaria, 
        horario_inicio, horario_fim, segunda, terca, quarta, quinta, sexta, sabado, 
        turno, observacoes, ativo, sala_fixa_id 
    ) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $input["turma"] ?? null,
            $input['codigo'],
            $input['nome'],
            $input['curso'],
            $input['periodo'],
            $input['professor'],
            $input['num_alunos'],
            $input['tipo_aula'] ?? null,
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
        
        // Código duplicado agora é permitido - comentado
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
                turma = COALESCE(?, turma),
                codigo = COALESCE(?, codigo),
                nome = COALESCE(?, nome),
                curso = COALESCE(?, curso),
                periodo = COALESCE(?, periodo),
                professor = COALESCE(?, professor),
                num_alunos = COALESCE(?, num_alunos),
                tipo_aula = COALESCE(?, tipo_aula),
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
            $input['turma'] ?? null,
            $input['codigo'] ?? null,
            $input['nome'] ?? null,
            $input['curso'] ?? null,
            $input['periodo'] ?? null,
            $input['professor'] ?? null,
            $input['num_alunos'] ?? null,
            $input['tipo_aula'] ?? null,
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

