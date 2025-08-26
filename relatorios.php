<?php
require_once __DIR__ . '/../vendor/autoload.php'; 

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Capturar qualquer erro e retornar JSON
set_error_handler(function($severity, $message, $file, $line) {
    return true;
});


ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'cursos':
            $response = obterCursos($database);
            break;
            
        case 'metricas':
            $response = obterMetricas($database);
            break;
            
        case 'grafico_status':
            $response = obterDadosGraficoStatus($database);
            break;
            
        case 'grafico_ocupacao_horario':
            $response = obterDadosGraficoOcupacaoHorario($database);
            break;
            
        case 'grafico_eficiencia_sala':
            $response = obterDadosGraficoEficienciaSala($database);
            break;
            
        case 'tabela_detalhada':
            $response = obterTabelaDetalhada($database);
            break;
            
        case 'exportar':
            $response = exportarRelatorio($database);
            break;

        case 'status':
            $response = obterStatus($database);
            break;
            
        case 'historico':
            $response = obterHistorico($database);
            break;

        case 'analise_conflitos':
            $response = obterAnaliseDeConflitos($database);
            break;
            
        default:
            throw new Exception('Ação não reconhecida');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $response
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Em api/relatorios.php

// Em api/relatorios.php

// >> SUBSTITUA A SUA FUNÇÃO obterMetricas INTEIRA POR ESTA VERSÃO FINAL <<
function obterMetricas($database) {
    // 1. Pega os filtros (igual na sua função)
    if (empty($_GET['periodo'])) {
        http_response_code(400 );
        echo json_encode(['success' => false, 'message' => 'O parâmetro "periodo" é obrigatório.']);
        exit;
    }
    $periodo = $_GET['periodo'];
    $filtro_curso = $_GET['curso'] ?? '';
    $filtro_tipo_sala = $_GET['tipo_sala'] ?? '';
    

    // 2. Encontrar a última data de execução (sua lógica original)
    $stmt_ultima_data = $database->prepare("SELECT MAX(created_at) FROM ensalamento WHERE periodo = ?");
    $stmt_ultima_data->execute([$periodo]);
    $ultima_data_execucao = $stmt_ultima_data->fetchColumn();

    $turmas_alocadas = 0;
    $salas_utilizadas = 0;
    $eficiencia_media_correta = 0;

    // 3. Se encontramos uma data, calculamos as métricas, AGORA COM FILTROS
    if ($ultima_data_execucao) {
        // =================================================================
        // >> SUA CONSULTA ORIGINAL E CORRETA <<
        // Esta consulta calcula TUDO junto e é inteiramente afetada pelos filtros.
        // =================================================================
        $sql_metricas = "
            SELECT 
                COUNT(DISTINCT e.turma_id) as total_alocadas,
                COUNT(DISTINCT e.sala_id) as total_salas,
                AVG(e.eficiencia) as media_eficiencia
            FROM ensalamento e
            LEFT JOIN turmas t ON e.turma_id = t.id
            LEFT JOIN salas s ON e.sala_id = s.id
            WHERE e.created_at = ? AND e.status = 'alocado'
        ";
        
        $params_metricas = [$ultima_data_execucao];

        // Adiciona os filtros à consulta de métricas (igual na sua função)
        if (!empty($filtro_curso)) {
            $sql_metricas .= " AND t.curso = ?";
            $params_metricas[] = $filtro_curso;
        }
        if (!empty($filtro_tipo_sala)) {
            $sql_metricas .= " AND s.tipo = ?";
            $params_metricas[] = $filtro_tipo_sala;
        }

        $stmt_metricas = $database->prepare($sql_metricas);
        $stmt_metricas->execute($params_metricas);
        $resultado_metricas = $stmt_metricas->fetch(PDO::FETCH_ASSOC);

        $turmas_alocadas = $resultado_metricas['total_alocadas'] ?? 0;
        $salas_utilizadas = $resultado_metricas['total_salas'] ?? 0;
        $eficiencia_media_correta = $resultado_metricas['media_eficiencia'] ?? 0;
    }
    
    // 4. Calcular a taxa de sucesso (sua lógica original, que já funciona)
    $sql_total_turmas = "SELECT COUNT(id) as total FROM turmas WHERE periodo = ?";
    $params_total_turmas = [$periodo];
    if (!empty($filtro_curso)) {
        $sql_total_turmas .= " AND curso = ?";
        $params_total_turmas[] = $filtro_curso;
    }
    $stmt_total_turmas = $database->prepare($sql_total_turmas);
    $stmt_total_turmas->execute($params_total_turmas);
    $total_turmas_periodo = $stmt_total_turmas->fetchColumn() ?? 0;
    
    $filtro_status = $_GET['status'] ?? '';
    if (!empty($filtro_status) && $filtro_status !== 'alocado') {
        $turmas_alocadas = 0;
        $salas_utilizadas = 0; 
        $eficiencia_media_correta = 0;
    }
    $taxa_sucesso = ($total_turmas_periodo > 0) ? round(($turmas_alocadas / $total_turmas_periodo) * 100, 1) : 0;

    // =================================================================
    // >> CORREÇÃO FINAL NO RETORNO <<
    // =================================================================
    // 5. Retornar os dados, garantindo que ambas as chaves sejam enviadas.
    return [
        'total_ensalamentos' => (int)$turmas_alocadas,
        'taxa_sucesso' => (float)$taxa_sucesso,
        'eficiencia_media' => (float)$eficiencia_media_correta,
        'salas_utilizadas' => (int)$salas_utilizadas, // Para o card do topo
        'utilizacao_salas' => (int)$salas_utilizadas  // <<-- ADICIONE ESTA LINHA para o indicador de performance
    ];
}




// Em api/relatorios.php

// >> SUBSTITUA A SUA FUNÇÃO obterDadosGraficoStatus INTEIRA POR ESTA <<
function obterDadosGraficoStatus($database) {
    // 1. Pega os filtros (período já estava, adicionamos os outros)
    if (empty($_GET['periodo'])) {
        http_response_code(400 );
        echo json_encode(['success' => false, 'message' => 'O parâmetro "periodo" é obrigatório.']);
        exit;
    }
    $periodo = $_GET['periodo'];
    $filtro_curso = $_GET['curso'] ?? '';
    $filtro_tipo_sala = $_GET['tipo_sala'] ?? '';
    $filtro_status = $_GET['status'] ?? '';

    // Se o usuário já filtrou por um status, o gráfico deve mostrar 100% para aquele status.
    if (!empty($filtro_status)) {
        $dados = ['alocadas' => 0, 'conflitos' => 0, 'pendentes' => 0];
        if ($filtro_status === 'alocado') $dados['alocadas'] = 1;
        if ($filtro_status === 'conflito') $dados['conflitos'] = 1;
        if ($filtro_status === 'pendente') $dados['pendentes'] = 1;
        return $dados;
    }

    // 2. Inicia a consulta base (a sua consulta original, com JOINs)
    $sql = "SELECT 
                e.status, 
                COUNT(e.id) as quantidade
            FROM ensalamento e
            LEFT JOIN turmas t ON e.turma_id = t.id
            LEFT JOIN salas s ON e.sala_id = s.id
            WHERE e.periodo = ?
    ";
    
    $params = [$periodo];

    // 3. Adiciona os filtros dinamicamente à consulta
    if (!empty($filtro_curso)) {
        $sql .= " AND t.curso = ?";
        $params[] = $filtro_curso;
    }
    if (!empty($filtro_tipo_sala)) {
        $sql .= " AND s.tipo = ?";
        $params[] = $filtro_tipo_sala;
    }

    // 4. Adiciona o final da sua consulta original
    $sql .= " GROUP BY e.status";
            
    // 5. Executa a consulta com os parâmetros corretos
    $stmt = $database->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // O resto da sua função permanece idêntico
    $dados = [
        'alocadas' => (int)($resultados['alocado'] ?? 0),
        'conflitos' => (int)($resultados['conflito'] ?? 0),
        'pendentes' => (int)($resultados['pendente'] ?? 0)
    ];

    return $dados;
}


// Em api/relatorios.php

// >> SUBSTITUA A SUA FUNÇÃO obterDadosGraficoOcupacaoHorario INTEIRA POR ESTA <<
function obterDadosGraficoOcupacaoHorario($database) {
    // 1. Pega os filtros (lógica que já funciona)
    if (empty($_GET['periodo'])) { /* ... sua validação ... */ }
    $periodo = $_GET['periodo'];
    $filtro_curso = $_GET['curso'] ?? '';
    $filtro_tipo_sala = $_GET['tipo_sala'] ?? '';
    $filtro_status = $_GET['status'] ?? '';

    if (!empty($filtro_status) && $filtro_status !== 'alocado') {
        return ['labels' => [], 'data' => []];
    }

    // =================================================================
    // >> NOVA LÓGICA DE CONSULTA <<
    // Passo A: Buscar todas as aulas relevantes com seus horários de início e fim.
    // =================================================================
    $sql = "SELECT 
                e.horario_inicio, 
                e.horario_fim
            FROM ensalamento e
            LEFT JOIN turmas t ON e.turma_id = t.id
            LEFT JOIN salas s ON e.sala_id = s.id
            WHERE e.periodo = ? AND e.status = 'alocado' AND e.horario_inicio IS NOT NULL
    ";
    
    $params = [$periodo];

    if (!empty($filtro_curso)) {
        $sql .= " AND t.curso = ?";
        $params[] = $filtro_curso;
    }
    if (!empty($filtro_tipo_sala)) {
        $sql .= " AND s.tipo = ?";
        $params[] = $filtro_tipo_sala;
    }

    $stmt = $database->prepare($sql);
    $stmt->execute($params);
    $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =================================================================
    // >> NOVA LÓGICA DE PROCESSAMENTO EM PHP <<
    // Passo B: Contar as salas ocupadas para cada hora do dia.
    // =================================================================
    $ocupacao_por_hora = array_fill(7, 16, 0); // Cria um array para as horas das 7h às 22h, tudo com 0.

    foreach ($aulas as $aula) {
        $hora_inicio = (int)substr($aula['horario_inicio'], 0, 2);
        $hora_fim = (int)substr($aula['horario_fim'], 0, 2);

        // Itera por cada hora que a aula ocupa e incrementa o contador.
        for ($h = $hora_inicio; $h < $hora_fim; $h++) {
            if (isset($ocupacao_por_hora[$h])) {
                $ocupacao_por_hora[$h]++;
            }
        }
    }
    
    // Passo C: Formatar os dados para o Chart.js (igual à sua lógica original)
    $labels = [];
    $valores = [];
    foreach ($ocupacao_por_hora as $hora => $quantidade) {
        $labels[] = str_pad($hora, 2, '0', STR_PAD_LEFT) . ':00';
        $valores[] = $quantidade;
    }
    
    return ['labels' => $labels, 'data' => $valores];
}



function obterCursos($database) {
    try {
        // A consulta busca todos os cursos distintos, ignora valores nulos ou vazios, e ordena em ordem alfabética.
        $sql = "SELECT DISTINCT curso FROM turmas WHERE curso IS NOT NULL AND curso != '' ORDER BY curso ASC";
        
        $stmt = $database->prepare($sql);
        $stmt->execute();
        
        // Retorna apenas uma lista simples de strings, ex: ["Direito", "Engenharia", "Medicina"]
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    } catch (Exception $e) {
        // Se houver um erro de banco de dados, lança uma exceção para ser capturada pelo bloco principal.
        throw new Exception('Erro ao buscar a lista de cursos: ' . $e->getMessage());
    }
}


// Em api/relatorios.php
// Em api/relatorios.php

// >> SUBSTITUA A SUA FUNÇÃO obterDadosGraficoEficienciaSala INTEIRA POR ESTA <<
function obterDadosGraficoEficienciaSala($database) {
    // 1. Pega os filtros (período já estava, adicionamos os outros)
    if (empty($_GET['periodo'])) {
        http_response_code(400 );
        echo json_encode(['success' => false, 'message' => 'O parâmetro "periodo" é obrigatório.']);
        exit;
    }
    $periodo = $_GET['periodo'];
    $filtro_curso = $_GET['curso'] ?? '';
    $filtro_tipo_sala = $_GET['tipo_sala'] ?? '';
    $filtro_status = $_GET['status'] ?? '';

    // Se o usuário filtrou por um status que não seja 'alocado', o gráfico fica vazio.
    if (!empty($filtro_status) && $filtro_status !== 'alocado') {
        return ['labels' => [], 'data' => []];
    }

    // 2. Inicia a consulta base (a sua consulta original)
    $sql = "SELECT 
                s.nome,
                ROUND(AVG(e.eficiencia), 1) as eficiencia
            FROM salas s
            JOIN ensalamento e ON s.id = e.sala_id
            JOIN turmas t ON e.turma_id = t.id
            WHERE e.periodo = ? AND e.status = 'alocado'
    ";
    
    $params = [$periodo];

    // 3. Adiciona os filtros dinamicamente à consulta
    if (!empty($filtro_curso)) {
        $sql .= " AND t.curso = ?";
        $params[] = $filtro_curso;
    }
    if (!empty($filtro_tipo_sala)) {
        $sql .= " AND s.tipo = ?";
        $params[] = $filtro_tipo_sala;
    }

    // 4. Adiciona o final da sua consulta original
    $sql .= " GROUP BY s.id, s.nome, s.capacidade
              HAVING SUM(t.num_alunos) > 0
              ORDER BY eficiencia DESC
              LIMIT 10";
            
    // 5. Executa a consulta com os parâmetros corretos
    $stmt = $database->prepare($sql);
    $stmt->execute($params);
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // O resto da sua função permanece idêntico
    $labels = [];
    $valores = [];
    foreach ($dados as $item) {
        $labels[] = $item['nome'];
        $valores[] = (float)$item['eficiencia'];
    }
    
    return ['labels' => $labels, 'data' => $valores];
}


// Em api/relatorios.php

// Substitua a sua função inteira por esta versão final, que implementa a sua ideia original.
function obterTabelaDetalhada($database) {
    // Sua validação de período (correta e original)
    if (empty($_GET['periodo'])) {
        http_response_code(400 );
        echo json_encode(['success' => false, 'message' => 'O parâmetro "periodo" é obrigatório.']);
        exit;
    }
    $periodo = $_GET['periodo'];
    $filtro_status = $_GET['status'] ?? '';
    $filtro_curso = $_GET['curso'] ?? '';
    $filtro_tipo_sala = $_GET['tipo_sala'] ?? '';

    // Sua definição de colunas (correta e original)
    $colunas = [
        ['campo' => 'turma_codigo', 'titulo' => 'Turma'], ['campo' => 'turma_nome', 'titulo' => 'Disciplina'],
        ['campo' => 'curso', 'titulo' => 'Curso'],
        ['campo' => 'professor', 'titulo' => 'Professor'], ['campo' => 'turno', 'titulo' => 'Turno'],
        ['campo' => 'dias_da_semana', 'titulo' => 'Dias'], ['campo' => 'horario', 'titulo' => 'Horário'],
        ['campo' => 'sala_alocada', 'titulo' => 'Sala Alocada'], ['campo' => 'status_final', 'titulo' => 'Status', 'tipo' => 'status'],
        ['campo' => 'detalhes_conflito', 'titulo' => 'Detalhes do Conflito']
    ];

    // Sua consulta principal (corrigida apenas com num_alunos)
    $sql_todas_as_turmas = "
        SELECT 
            t.id, t.codigo, t.nome, t.professor, t.turno, t.num_alunos, t.curso,
            t.horario_inicio, t.horario_fim,
            t.segunda, t.terca, t.quarta, t.quinta, t.sexta, t.sabado,
            t.sala_fixa_id, s.codigo as sala_fixa_codigo
        FROM turmas t
        LEFT JOIN salas s ON t.sala_fixa_id = s.id
        WHERE t.periodo = ?
    ";
    $stmt_turmas = $database->prepare($sql_todas_as_turmas);
    $stmt_turmas->execute([$periodo]);
    $todas_as_turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

    // Sua lógica de detecção de conflitos (correta e original)
    $conflitos_mapeados = [];
    for ($i = 0; $i < count($todas_as_turmas); $i++) {
        for ($j = $i + 1; $j < count($todas_as_turmas); $j++) {
            $t1 = $todas_as_turmas[$i]; $t2 = $todas_as_turmas[$j];
            $horario_colide = ($t1['horario_inicio'] < $t2['horario_fim']) && ($t1['horario_fim'] > $t2['horario_inicio']);
            if ($horario_colide) {
                $dias_conflitantes = [];
                $dias_semana = ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];
                foreach ($dias_semana as $dia) {
                    if ($t1[$dia] && $t2[$dia]) $dias_conflitantes[] = ucfirst($dia);
                }
                if (!empty($dias_conflitantes)) {
                    $motivo = '';
                    if ($t1['sala_fixa_id'] && $t1['sala_fixa_id'] === $t2['sala_fixa_id']) {
                        $motivo = "Sala Fixa " . $t1['sala_fixa_codigo'];
                    } elseif ($t1['professor'] === $t2['professor']) {
                        $motivo = "Professor(a) " . $t1['professor'];
                    }
                    if ($motivo) {
                        $mensagem = sprintf("Conflito com Turma %s por %s nos dias: %s", $t2['codigo'], $motivo, implode(', ', $dias_conflitantes));
                        $conflitos_mapeados[$t1['id']][] = $mensagem;
                        $mensagem_reversa = sprintf("Conflito com Turma %s por %s nos dias: %s", $t1['codigo'], $motivo, implode(', ', $dias_conflitantes));
                        $conflitos_mapeados[$t2['id']][] = $mensagem_reversa;
                    }
                }
            }
        }
    }

    // Sua lógica de construção de linhas (correta e original)
    $linhas = [];
    foreach ($todas_as_turmas as $turma) {
        $sql_ensalamentos = "
            SELECT 
                e.dia_semana, 
                e.status, 
                s.codigo as sala_codigo, 
                s.capacidade as sala_capacidade,
                s.tipo as sala_tipo -- <<-- ESTA É A LINHA QUE HABILITA O FILTRO
            FROM ensalamento e 
            LEFT JOIN salas s ON e.sala_id = s.id
            WHERE e.turma_id = ? AND e.periodo = ?
        ";
        $stmt_ensalamentos = $database->prepare($sql_ensalamentos);
        $stmt_ensalamentos->execute([$turma['id'], $periodo]);
        $ensalamentos = $stmt_ensalamentos->fetchAll(PDO::FETCH_ASSOC);
        $salas_alocadas = []; $status_final = 'Pendente'; $capacidade_sala = null;
        $tipos_de_sala_da_turma = []; // Inicializa o array aqui, para cada turma
if (!empty($ensalamentos)) {
    $status_temp = 'alocado';
    foreach ($ensalamentos as $e) {
        $salas_alocadas[] = $e['sala_codigo'] ?? 'N/A';
        if ($e['status'] === 'conflito') $status_temp = 'conflito';
        if (isset($e['sala_capacidade'])) $capacidade_sala = (int)$e['sala_capacidade'];
        
        // >> ADIÇÃO IMPORTANTE AQUI <<
        // Adiciona o tipo da sala ao nosso array, se ele existir.
        if (!empty($e['sala_tipo'])) {
            $tipos_de_sala_da_turma[] = $e['sala_tipo'];
        }
    }
    $status_final = $status_temp;
}

        $detalhes = $conflitos_mapeados[$turma['id']] ?? [];

                $dias_turma = [];
        $mapa_dias = ['segunda' => 'Segunda', 'terca' => 'Terça', 'quarta' => 'Quarta', 'quinta' => 'Quinta', 'sexta' => 'Sexta', 'sabado' => 'Sábado'];
        foreach ($mapa_dias as $coluna_dia => $nome_dia) {
            if (!empty($turma[$coluna_dia])) { // Verifica se a coluna do dia (ex: 'segunda') está marcada como 1 (true)
                $dias_turma[] = $nome_dia;
            }
        }

        if ($status_final === 'conflito' && empty($detalhes)) {
            $detalhes[] = 'Alocação incompleta (provavelmente sem sala designada).';
        }

        // =================================================================
        // >> A ÚNICA E MÍNIMA MODIFICAÇÃO, EXATAMENTE COMO VOCÊ PEDIU <<
        // =================================================================
        if ($status_final === 'alocado' && $capacidade_sala !== null && (int)$turma['num_alunos'] > $capacidade_sala) {
            // Adiciona a mensagem de superlotação no início do array de detalhes.
            // NÃO altera o $status_final.
            array_unshift($detalhes, sprintf(
                "Superlotação: Turma com %d alunos em sala com capacidade para %d.",
                $turma['num_alunos'], $capacidade_sala
            ));
        }
        // =================================================================
        if (!empty($filtro_curso) && $turma['curso'] !== $filtro_curso) {
            continue;
        }
        if (!empty($filtro_tipo_sala)) {
            // Se o tipo de sala que o usuário filtrou NÃO estiver na lista de tipos
            // de sala em que esta turma foi alocada, nós pulamos a turma.
            if (!in_array($filtro_tipo_sala, $tipos_de_sala_da_turma)) {
                continue;
            }
        }
        if (!empty($filtro_status) && strtolower($status_final) !== strtolower($filtro_status)) {
            continue;
        }
        $linhas[] = [
            'turma_codigo' => $turma['codigo'], 'turma_nome' => $turma['nome'], 'curso' => $turma['curso'],
            'professor' => $turma['professor'], 'turno' => $turma['turno'],
            'dias_da_semana' => !empty($dias_turma) ? implode(', ', array_unique($dias_turma)) : 'N/A',
            'horario' => substr($turma['horario_inicio'], 0, 5) . ' - ' . substr($turma['horario_fim'], 0, 5),
            'sala_alocada' => !empty($salas_alocadas) ? implode(', ', array_unique($salas_alocadas)) : 'N/A',
            'status_final' => $status_final, // Usa o status original ('alocado', 'conflito', 'pendente')
            'detalhes_conflito' => implode('; ', $detalhes) // Adiciona a mensagem de superlotação aqui
        ];
    }

    return ['colunas' => $colunas, 'linhas' => $linhas];
}




function exportarRelatorio($database) {
    // 1. Obter os dados da mesma forma que antes
    $dados_formatados = obterTabelaDetalhada($database);
    $colunas = $dados_formatados['colunas'];
    $linhas = $dados_formatados['linhas'];

    // 2. Criar um novo objeto Spreadsheet (uma planilha em memória)
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // 3. Escrever o cabeçalho na planilha
    $colunaLetra = 'A';
    foreach ($colunas as $coluna) {
        $sheet->setCellValue($colunaLetra . '1', $coluna['titulo']);
        $colunaLetra++;
    }

    // 4. Escrever os dados na planilha
    $linhaNumero = 2; // Começar na linha 2, abaixo do cabeçalho
    foreach ($linhas as $linha) {
        $colunaLetra = 'A';
        foreach ($colunas as $coluna) {
            $valor = $linha[$coluna['campo']] ?? '';
            $sheet->setCellValue($colunaLetra . $linhaNumero, $valor);
            $colunaLetra++;
        }
        $linhaNumero++;
    }

    // 5. Adicionar um pouco de estilo (Opcional, mas recomendado)
    $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true); // Negrito no cabeçalho
    foreach (range('A', $sheet->getHighestColumn()) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true); // Auto-ajuste da largura das colunas
    }

    // 6. Criar o "escritor" de arquivos XLSX e salvar em um arquivo temporário
    $writer = new Xlsx($spreadsheet);
    $temp_file = tempnam(sys_get_temp_dir(), 'relatorio_'); // Cria um arquivo temporário seguro
    $writer->save($temp_file);

    // 7. Ler o conteúdo do arquivo temporário e codificar em Base64
    $file_content = file_get_contents($temp_file);
    unlink($temp_file); // Apagar o arquivo temporário

    // 8. Retornar o conteúdo para o JavaScript
    return [
        'filename' => 'relatorio_detalhado_' . date('Y-m-d') . '.xlsx', // <-- MUDOU PARA .xlsx
        'content' => base64_encode($file_content)
    ];
}



// Em api/relatorios.php, adicione estas duas funções no final do arquivo.

function obterStatus($database) {
    if (empty($_GET['periodo'])) {
    // Se o período não for fornecido, encerra a execução com um erro claro.
    http_response_code(400 ); // 400 = Bad Request
    echo json_encode(['success' => false, 'message' => 'O parâmetro "periodo" é obrigatório.']);
    exit; // Interrompe o script
}
$periodo = $_GET['periodo'];
    
    $stmt_turmas = $database->prepare("SELECT COUNT(*) as total FROM turmas WHERE periodo = ? AND ativo = 1");
    $stmt_turmas->execute([$periodo]);
    $total_turmas = $stmt_turmas->fetchColumn();
    
    $stmt_salas = $database->prepare("SELECT COUNT(*) as total FROM salas WHERE ativo = 1");
    $stmt_salas->execute();
    $total_salas = $stmt_salas->fetchColumn();
    
    $stmt_alocadas = $database->prepare("SELECT COUNT(DISTINCT turma_id) as total FROM ensalamento WHERE periodo = ? AND status = 'alocado'");
    $stmt_alocadas->execute([$periodo]);
    $turmas_alocadas = $stmt_alocadas->fetchColumn();
    
    return [
        'periodo' => $periodo,
        'total_turmas' => (int)$total_turmas,
        'total_salas' => (int)$total_salas,
        'turmas_alocadas' => (int)$turmas_alocadas,
        'status_geral' => ($total_turmas > 0) ? 'Pronto para ensalamento' : 'Sem turmas cadastradas'
    ];
}

function obterHistorico($database) {
    if (empty($_GET['periodo'])) {
    // Se o período não for fornecido, encerra a execução com um erro claro.
    http_response_code(400 ); // 400 = Bad Request
    echo json_encode(['success' => false, 'message' => 'O parâmetro "periodo" é obrigatório.']);
    exit; // Interrompe o script
}
$periodo = $_GET['periodo'];
    
    // Esta consulta agrupa por execução de ensalamento, assumindo que múltiplos INSERTs podem ter o mesmo created_at
    $stmt = $database->prepare("
        SELECT 
            DATE(created_at) as data_execucao,
            algoritmo_usado,
            COUNT(DISTINCT turma_id) as turmas_processadas,
            AVG(eficiencia) as eficiencia_media
        FROM ensalamento
        WHERE periodo = ?
        GROUP BY data_execucao, algoritmo_usado
        ORDER BY data_execucao DESC
        LIMIT 5
    ");
    
    $stmt->execute([$periodo]);
    $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $historico;
}


function obterAnaliseDeConflitos($database) {
    if (empty($_GET['periodo'])) {
    // Se o período não for fornecido, encerra a execução com um erro claro.
    http_response_code(400 ); // 400 = Bad Request
    echo json_encode(['success' => false, 'message' => 'O parâmetro "periodo" é obrigatório.']);
    exit; // Interrompe o script
}
$periodo = $_GET['periodo'];

    // A primeira parte da sua função está correta e pode ser mantida
    $sql = "SELECT 
                t.sala_fixa_id,
                GROUP_CONCAT(t.id) as turmas_ids,
                COUNT(t.id) as total_turmas_na_sala
            FROM turmas t
            WHERE t.periodo = ? AND t.sala_fixa_id IS NOT NULL
            GROUP BY t.sala_fixa_id
            HAVING total_turmas_na_sala > 1";

    $stmt = $database->prepare($sql);
    $stmt->execute([$periodo]);
    $grupos_de_salas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $conflitos_reais = [];

    foreach ($grupos_de_salas as $grupo) {
        $ids_das_turmas = explode(',', $grupo['turmas_ids']);
        
        $placeholders = rtrim(str_repeat('?,', count($ids_das_turmas)), ',');
        $sql_turmas = "SELECT id, codigo, nome, curso, horario_inicio, horario_fim, segunda, terca, quarta, quinta, sexta, sabado, sala_fixa_id FROM turmas WHERE id IN ($placeholders)";
        $stmt_turmas = $database->prepare($sql_turmas);
        $stmt_turmas->execute($ids_das_turmas);
        $turmas_na_sala = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

        // Comparar cada par de turmas
        for ($i = 0; $i < count($turmas_na_sala); $i++) {
            for ($j = $i + 1; $j < count($turmas_na_sala); $j++) {
                $t1 = $turmas_na_sala[$i];
                $t2 = $turmas_na_sala[$j];

                // =================================================================
                // LÓGICA DE DETECÇÃO DE SOBREPOSIÇÃO (VERSÃO CORRIGIDA E PRECISA)
                // =================================================================

                // 1. Verificar se há sobreposição de horário
                $horario_colide = ($t1['horario_inicio'] < $t2['horario_fim']) && ($t1['horario_fim'] > $t2['horario_inicio']);

                if ($horario_colide) {
                    // 2. Se os horários colidem, verificar em QUAIS DIAS isso acontece
                    $dias_semana = ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];
                    $dias_reais_do_conflito = [];

                    foreach ($dias_semana as $dia) {
                        // O conflito só existe naquele dia se AMBAS as turmas ocorrerem nele
                        if ($t1[$dia] && $t2[$dia]) {
                            $dias_reais_do_conflito[] = ucfirst($dia);
                        }
                    }

                    // 3. Se encontramos pelo menos um dia de conflito, registrar o problema
                    if (!empty($dias_reais_do_conflito)) {
                        // Encontramos um conflito real!
                        $sala_info = $database->query("SELECT codigo, nome FROM salas WHERE id = " . $t1['sala_fixa_id'])->fetch();
                        
                        // 4. Calcular o intervalo de tempo exato da sobreposição
                        $inicio_conflito = max($t1['horario_inicio'], $t2['horario_inicio']);
                        $fim_conflito = min($t1['horario_fim'], $t2['horario_fim']);
                        
                        $conflitos_reais[] = [
                            'sala_codigo' => $sala_info['codigo'],
                            'sala_nome' => $sala_info['nome'],
                            'turma1_codigo' => $t1['codigo'],
                            'turma1_nome' => $t1['nome'],
                            'turma1_curso' => $t1['curso'],
                            'turma2_codigo' => $t2['codigo'],
                            'turma2_nome' => $t2['nome'],
                            'turma2_curso' => $t2['curso'],
                            'dias' => implode(', ', $dias_reais_do_conflito), // Apenas os dias da sobreposição
                            'horario' => substr($inicio_conflito, 0, 5) . ' - ' . substr($fim_conflito, 0, 5) // O horário exato do conflito
                        ];
                    }
                }
                // =================================================================
            }
        }
    }
    
    return $conflitos_reais;
}


?>

