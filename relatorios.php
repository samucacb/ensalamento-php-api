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
 * API Relatórios
 * Fornece dados para relatórios e analytics
 */

// Suprimir erros PHP para garantir JSON válido
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

function obterCursos($database) {
    $stmt = $database->prepare("SELECT DISTINCT curso FROM turmas WHERE ativo = 1 ORDER BY curso");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Em api/relatorios.php

function obterMetricas($database) {
    // Passo 1: Obter os filtros da URL
    $periodo = $_GET['periodo'] ?? '2025.1';
    // Adicione outros filtros se quiser que as métricas mudem com eles
    // $curso = $_GET['curso'] ?? ''; 

    // Passo 2: Construir a base da consulta com os filtros
    $baseSql = "FROM ensalamento WHERE periodo = ?";
    $params = [$periodo];

    // Passo 3: Executar as consultas usando a base
    
    // Total de turmas alocadas
    $stmt_alocadas = $database->prepare("SELECT COUNT(DISTINCT turma_id) as total " . $baseSql . " AND status = 'alocado'");
    $stmt_alocadas->execute($params);
    $turmas_alocadas = $stmt_alocadas->fetchColumn();

    // Total de salas utilizadas
    $stmt_salas = $database->prepare("SELECT COUNT(DISTINCT sala_id) as total " . $baseSql . " AND status = 'alocado'");
    $stmt_salas->execute($params);
    $salas_utilizadas = $stmt_salas->fetchColumn();

    // Total de turmas no período para calcular a taxa de sucesso
    $stmt_total_turmas = $database->prepare("SELECT COUNT(id) as total FROM turmas WHERE periodo = ?");
    $stmt_total_turmas->execute([$periodo]);
    $total_turmas_periodo = $stmt_total_turmas->fetchColumn();

    // Calcular as métricas
    $taxa_sucesso = ($total_turmas_periodo > 0) ? round(($turmas_alocadas / $total_turmas_periodo) * 100, 1) : 0;

    // Retornar os dados no formato que o JavaScript espera
    return [
        'total_ensalamentos' => (int)$turmas_alocadas,
        'taxa_sucesso' => (float)$taxa_sucesso,
        'eficiencia_media' => (float)$taxa_sucesso, // Usando taxa de sucesso como eficiência média por enquanto
        'salas_utilizadas' => (int)$salas_utilizadas
    ];
}


// Em api/relatorios.php

function obterDadosGraficoStatus($database) {
    $periodo = $_GET['periodo'] ?? '2025.1';
    
    $sql = "SELECT status, COUNT(*) as quantidade FROM ensalamento WHERE periodo = ? GROUP BY status";
    
    $stmt = $database->prepare($sql);
    $stmt->execute([$periodo]);
    $resultados = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Pega os resultados como ['status' => quantidade]

    // Garante que todos os status existam na resposta, mesmo que com valor 0,
    // e usa a palavra correta "alocado".
    $dados = [
        'alocadas' => (int)($resultados['alocado'] ?? 0), // <-- A CORREÇÃO ESTÁ AQUI
        'conflitos' => (int)($resultados['conflito'] ?? 0),
        'pendentes' => (int)($resultados['pendente'] ?? 0)
    ];

    // O JavaScript que cria o gráfico de pizza espera os dados neste formato.
    return $dados;
}



// Em api/relatorios.php
function obterDadosGraficoOcupacaoHorario($database) {
    $periodo = $_GET['periodo'] ?? '2025.1';
    
    $sql = "SELECT 
                HOUR(horario_inicio) as hora, 
                COUNT(id) as quantidade
            FROM ensalamento
            WHERE periodo = ? AND status = 'alocado' AND horario_inicio IS NOT NULL -- <-- CORRIGIDO AQUI
            GROUP BY hora
            ORDER BY hora ASC";
    
    $stmt = $database->prepare($sql);
    $stmt->execute([$periodo]);
    // ... resto da função ...
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $valores = [];
    foreach ($dados as $item) {
        $labels[] = str_pad($item['hora'], 2, '0', STR_PAD_LEFT) . ':00';
        $valores[] = (int)$item['quantidade'];
    }
    
    return ['labels' => $labels, 'data' => $valores];
}


// Em api/relatorios.php
function obterDadosGraficoEficienciaSala($database) {
    $periodo = $_GET['periodo'] ?? '2025.1';

    $sql = "SELECT 
                s.nome,
                ROUND((SUM(t.num_alunos) / s.capacidade) * 100, 1) as eficiencia
            FROM salas s
            JOIN ensalamento e ON s.id = e.sala_id
            JOIN turmas t ON e.turma_id = t.id
            WHERE e.periodo = ? AND e.status = 'alocado' -- <-- CORRIGIDO AQUI
            GROUP BY s.id, s.nome, s.capacidade
            HAVING SUM(t.num_alunos) > 0
            ORDER BY eficiencia DESC
            LIMIT 10";
            
    $stmt = $database->prepare($sql);
    $stmt->execute([$periodo]);
    // ... resto da função ...
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $valores = [];
    foreach ($dados as $item) {
        $labels[] = $item['nome'];
        $valores[] = (float)$item['eficiencia'];
    }
    
    return ['labels' => $labels, 'data' => $valores];
}



// Em api/relatorios.php

function obterTabelaDetalhada($database) {
    // Passo 1: Obter os filtros da URL
    $periodo = $_GET['periodo'] ?? '2025.1';
    // Adicione outros filtros aqui se necessário

    // Passo 2: Definir as colunas que a tabela terá
    $colunas = [
        ['campo' => 'turma_codigo', 'titulo' => 'Turma'],
        ['campo' => 'turma_nome', 'titulo' => 'Disciplina'],
        ['campo' => 'professor', 'titulo' => 'Professor'],
        ['campo' => 'num_alunos', 'titulo' => 'Alunos'],
        ['campo' => 'sala_codigo', 'titulo' => 'Sala Alocada'],
        ['campo' => 'capacidade', 'titulo' => 'Cap. Sala'],
        ['campo' => 'status', 'titulo' => 'Status', 'tipo' => 'status'] // 'tipo' para formatação especial
    ];

    // Passo 3: Executar a consulta para buscar as linhas
    $sql = "SELECT 
                t.codigo as turma_codigo,
                t.nome as turma_nome,
                t.professor,
                t.num_alunos,
                s.codigo as sala_codigo,
                s.capacidade,
                e.status
            FROM ensalamento e
            JOIN turmas t ON e.turma_id = t.id
            JOIN salas s ON e.sala_id = s.id
            WHERE e.periodo = ?";
    
    $stmt = $database->prepare($sql);
    $stmt->execute([$periodo]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Passo 4: Retornar o objeto completo que o JavaScript espera
    return [
        'colunas' => $colunas,
        'linhas' => $linhas
    ];
}


function exportarRelatorio($database) {
    $dados = obterTabelaDetalhada($database);
    
    // Converter para CSV
    $csv = "Turma,Nome,Professor,Alunos,Sala,Capacidade,Status,Data\n";
    
    foreach ($dados as $linha) {
        $csv .= sprintf(
            "%s,%s,%s,%d,%s,%d,%s,%s\n",
            $linha['turma_codigo'],
            $linha['turma_nome'],
            $linha['professor'],
            $linha['num_alunos'],
            $linha['sala_codigo'] ?? '',
            $linha['capacidade'] ?? 0,
            $linha['status'] ?? 'não alocada',
            $linha['created_at'] ?? ''
        );
    }
    
    return [
        'filename' => 'relatorio_ensalamento_' . date('Y-m-d') . '.csv',
        'content' => base64_encode($csv)
    ];
}

// Em api/relatorios.php, adicione estas duas funções no final do arquivo.

function obterStatus($database) {
    $periodo = $_GET['periodo'] ?? '2025.1';
    
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
    $periodo = $_GET['periodo'] ?? '2025.1';
    
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

// Adicione esta função ao final do arquivo api/relatorios.php

// Em api/relatorios.php

function obterAnaliseDeConflitos($database) {
    $periodo = $_GET['periodo'] ?? '2025.1';

    // Consulta Simplificada: Pega todas as turmas com sala fixa e agrupa por sala.
    $sql = "SELECT 
                t.sala_fixa_id,
                GROUP_CONCAT(t.id) as turmas_ids,
                COUNT(t.id) as total_turmas_na_sala
            FROM turmas t
            WHERE t.periodo = ? AND t.sala_fixa_id IS NOT NULL
            GROUP BY t.sala_fixa_id
            HAVING total_turmas_na_sala > 1"; // Só nos interessam salas com mais de 1 turma forçada

    $stmt = $database->prepare($sql);
    $stmt->execute([$periodo]);
    $grupos_de_salas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $conflitos_reais = [];

    foreach ($grupos_de_salas as $grupo) {
        $ids_das_turmas = explode(',', $grupo['turmas_ids']);
        
        // Buscar os detalhes completos das turmas em conflito
        $placeholders = rtrim(str_repeat('?,', count($ids_das_turmas)), ',');
        $sql_turmas = "SELECT id, codigo, nome, horario_inicio, horario_fim, segunda, terca, quarta, quinta, sexta, sabado, sala_fixa_id FROM turmas WHERE id IN ($placeholders)";
        $stmt_turmas = $database->prepare($sql_turmas);
        $stmt_turmas->execute($ids_das_turmas);
        $turmas_na_sala = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

        // Agora, comparar cada par de turmas dentro do mesmo grupo
        for ($i = 0; $i < count($turmas_na_sala); $i++) {
            for ($j = $i + 1; $j < count($turmas_na_sala); $j++) {
                $t1 = $turmas_na_sala[$i];
                $t2 = $turmas_na_sala[$j];

                // Verificar sobreposição de horário
                $horario_colide = ($t1['horario_inicio'] < $t2['horario_fim']) && ($t1['horario_fim'] > $t2['horario_inicio']);
                
                // Verificar sobreposição de dias
                $dias_colidem = ($t1['segunda'] && $t2['segunda']) || ($t1['terca'] && $t2['terca']) || 
                                ($t1['quarta'] && $t2['quarta']) || ($t1['quinta'] && $t2['quinta']) || 
                                ($t1['sexta'] && $t2['sexta']) || ($t1['sabado'] && $t2['sabado']);

                if ($horario_colide && $dias_colidem) {
                    // Encontramos um conflito real!
                    $sala_info = $database->query("SELECT codigo, nome FROM salas WHERE id = " . $t1['sala_fixa_id'])->fetch();
                    
                    $conflitos_reais[] = [
                        'sala_codigo' => $sala_info['codigo'],
                        'sala_nome' => $sala_info['nome'],
                        'turma1_codigo' => $t1['codigo'],
                        'turma1_nome' => $t1['nome'],
                        'turma2_codigo' => $t2['codigo'],
                        'turma2_nome' => $t2['nome'],
                        'dias' => 'Verificar', // Simplificado para depuração
                        'horario' => $t1['horario_inicio'] . ' - ' . $t1['horario_fim']
                    ];
                }
            }
        }
    }
    
    return $conflitos_reais;
}

?>

