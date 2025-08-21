/**
 * Dashboard JavaScript
 * Funcionalidades interativas do dashboard principal
 */

// Variaveis globais
let dashboardCharts = {};
let refreshInterval;

// Inicializacao
document.addEventListener('DOMContentLoaded', function() {
        initializeDashboard();
        startAutoRefresh();
    const modalEnsalamento = document.getElementById('modalEnsalamento');
    if (modalEnsalamento) {
        modalEnsalamento.addEventListener('hide.bs.modal', function () {
            if (document.activeElement) {
                document.activeElement.blur();
            }
        });
    }
});

function initializeDashboard() {
    loadDashboardData();
    setupEventListeners();
}

function setupEventListeners() {
    // Botao de atualizar
    const refreshBtn = document.querySelector('[onclick="atualizarDados()"]');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            loadDashboardData();
        });
    }
    
    // Botao de executar ensalamento
    const executeBtn = document.querySelector('[onclick="executarEnsalamento()"]');
    if (executeBtn) {
        executeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showEnsalamentoModal();
        });
    }
}

function loadDashboardData() {
    showLoading();
    const periodo = document.getElementById('periodo').value;
    Promise.all([
        loadStatistics(periodo),
        loadCharts(periodo),
        loadActivities(periodo),
        loadAlerts(periodo)
    ]).then(() => {
        hideLoading();
    }).catch(error => {
        console.error('Erro ao carregar dashboard:', error);
        hideLoading();
        showError('Erro ao carregar dados do dashboard');
    });
}

function loadStatistics(periodo) {
    const params = new URLSearchParams({ action: 'estatisticas', periodo: periodo });
    return fetch(`api/dashboard.php?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStatistics(data.data);
            } else {
                throw new Error(data.message);
            }
        });
}

function updateStatistics(stats) {
    const elements = {
        'total-salas': stats.total_salas || 0,
        'total-turmas': stats.total_turmas || 0,
        'turmas-alocadas': stats.turmas_alocadas || 0,
        'eficiencia-media': (stats.eficiencia_media || 0).toFixed(1) + '%'
    };
    
    Object.entries(elements).forEach(([id, value]) => {
        const element = document.getElementById(id);
        if (element) {
            animateNumber(element, value);
        }
    });
}

function animateNumber(element, finalValue) {
    const isPercentage = typeof finalValue === 'string' && finalValue.includes('%');
    const numericValue = isPercentage ? parseFloat(finalValue) : parseInt(finalValue);
    const currentValue = parseInt(element.textContent) || 0;
    
    if (currentValue === numericValue) return;
    
    const duration = 1000;
    const steps = 30;
    const increment = (numericValue - currentValue) / steps;
    let current = currentValue;
    let step = 0;
    
    const timer = setInterval(() => {
        step++;
        current += increment;
        
        if (step >= steps) {
            clearInterval(timer);
            element.textContent = finalValue;
        } else {
            const displayValue = isPercentage ? 
                current.toFixed(1) + '%' : 
                Math.round(current);
            element.textContent = displayValue;
        }
    }, duration / steps);
}

function loadCharts(periodo) { // << MUDANÇA
    return Promise.all([
        loadStatusChart(periodo), // << MUDANÇA
        loadOccupancyChart(periodo) // << MUDANÇA
    ]);
}

function loadStatusChart(periodo) {
    const params = new URLSearchParams({ action: 'grafico_status', periodo: periodo });
    return fetch(`api/dashboard.php?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                createStatusChart(data.data);
            }
        });
}

function loadOccupancyChart(periodo) {
    const params = new URLSearchParams({ action: 'grafico_ocupacao', periodo: periodo });
    return fetch(`api/dashboard.php?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                createOccupancyChart(data.data);
            }
        });
}

function createStatusChart(data) {
    const ctx = document.getElementById('chart-status');
    if (!ctx) return;
    
    // Destruir grafico anterior se existir
    if (dashboardCharts.status) {
        dashboardCharts.status.destroy();
    }
    
    dashboardCharts.status = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Alocadas', 'Conflitos', 'Pendentes'],
            datasets: [{
                data: [data.alocadas || 0, data.conflitos || 0, data.pendentes || 0],
                backgroundColor: ['#28a745', '#dc3545', '#ffc107'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((context.raw / total) * 100).toFixed(1) : 0;
                            return `${context.label}: ${context.raw} (${percentage}%)`;
                        }
                    }
                }
            },
            animation: {
                animateRotate: true,
                duration: 1000
            }
        }
    });
}

function createOccupancyChart(data) {
    const ctx = document.getElementById('chart-ocupacao');
    if (!ctx) return;
    
    // Destruir grafico anterior se existir
    if (dashboardCharts.occupancy) {
        dashboardCharts.occupancy.destroy();
    }
    
    dashboardCharts.occupancy = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels || [],
            datasets: [{
                label: 'Aulas Alocadas',
                data: data.values || [],
                backgroundColor: '#007bff',
                borderColor: '#0056b3',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    },
                    grid: {
                        color: '#e9ecef'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: '#007bff',
                    borderWidth: 1
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            }
        }
    });
}

function loadActivities(periodo) {
     const params = new URLSearchParams({ action: 'atividades', periodo: periodo });
    return fetch(`api/dashboard.php?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayActivities(data.data);
            }
        });
}

function displayActivities(activities) {
    const container = document.getElementById('ultimas-atividades');
    if (!container) return;
    
    if (!activities || activities.length === 0) {
        container.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-clock-history fs-1"></i>
                <p class="mt-2">Nenhuma atividade recente</p>
            </div>
        `;
        return;
    }
    
    let html = '<div class="list-group list-group-flush">';
    activities.forEach((activity, index) => {
        const icon = getActivityIcon(activity.acao);
        const timeAgo = getTimeAgo(activity.created_at);
        
        html += `
            <div class="list-group-item border-0 px-0 ${index === 0 ? 'pt-0' : ''}">
                <div class="d-flex align-items-start">
                    <div class="flex-shrink-0 me-3">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                            <i class="bi ${icon} fs-6"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fs-6">${activity.descricao}</h6>
                        <p class="mb-1 text-muted small">${activity.usuario || 'Sistema'}</p>
                        <small class="text-muted">${timeAgo}</small>
                    </div>
                </div>
            </div>
        `;
    });
    html += '</div>';
    
    container.innerHTML = html;
}

function getActivityIcon(action) {
    const icons = {
        'INSERT': 'bi-plus-circle',
        'UPDATE': 'bi-pencil-circle',
        'DELETE': 'bi-trash-circle',
        'ENSALAMENTO': 'bi-calendar-check'
    };
    return icons[action] || 'bi-info-circle';
}

function getTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);
    
    if (diffMins < 1) return 'Agora mesmo';
    if (diffMins < 60) return `${diffMins} min atras`;
    if (diffHours < 24) return `${diffHours}h atras`;
    if (diffDays < 7) return `${diffDays}d atras`;
    
    return date.toLocaleDateString('pt-BR');
}

function loadAlerts(periodo) {
    const params = new URLSearchParams({ action: 'alertas', periodo: periodo });
     return fetch(`api/dashboard.php?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayAlerts(data.data);
            }
        });
}

function displayAlerts(alerts) {
    const container = document.getElementById('alertas-sistema');
    if (!container) return;
    
    if (!alerts || alerts.length === 0) {
        container.innerHTML = `
            <div class="alert alert-success border-0 mb-0">
                <i class="bi bi-check-circle me-2"></i>
                Sistema funcionando normalmente
            </div>
        `;
        return;
    }
    
    let html = '';
    alerts.forEach(alert => {
        const alertClass = getAlertClass(alert.tipo);
        const icon = getAlertIcon(alert.tipo);
        
        html += `
            <div class="alert alert-${alertClass} border-0 mb-2">
                <i class="bi ${icon} me-2"></i>
                ${alert.mensagem}
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function getAlertClass(type) {
    const classes = {
        'erro': 'danger',
        'aviso': 'warning',
        'info': 'info',
        'sucesso': 'success'
    };
    return classes[type] || 'info';
}

function getAlertIcon(type) {
    const icons = {
        'erro': 'bi-exclamation-triangle',
        'aviso': 'bi-exclamation-circle',
        'info': 'bi-info-circle',
        'sucesso': 'bi-check-circle'
    };
    return icons[type] || 'bi-info-circle';
}

function showEnsalamentoModal() {
    const modal = new bootstrap.Modal(document.getElementById('modalEnsalamento'));
    modal.show();
}

function showLoading() {
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        if (!card.classList.contains('loading')) {
            card.classList.add('loading');
        }
    });
}

function hideLoading() {
    const cards = document.querySelectorAll('.card.loading');
    cards.forEach(card => {
        card.classList.remove('loading');
    });
}

function showError(message) {
    // Criar toast de erro
    const toastHtml = `
        <div class="toast align-items-center text-white bg-danger border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    
    // Adicionar ao container de toasts (criar se nao existir)
    let toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
        document.body.appendChild(toastContainer);
    }
    
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    
    // Mostrar toast
    const toastElement = toastContainer.lastElementChild;
    const toast = new bootstrap.Toast(toastElement);
    toast.show();
    
    // Remover apos esconder
    toastElement.addEventListener('hidden.bs.toast', () => {
        toastElement.remove();
    });
}

function startAutoRefresh() {
    // Atualizar a cada 5 minutos
    refreshInterval = setInterval(() => {
        loadDashboardData();
    }, 5 * 60 * 1000);
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// Parar refresh quando a pagina nao estiver visivel
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopAutoRefresh();
    } else {
        startAutoRefresh();
    }
});

// Funcoes globais para compatibilidade
window.atualizarDados = function() {
    loadDashboardData();
};

window.executarEnsalamento = function() {
    showEnsalamentoModal();
};

window.confirmarEnsalamento = function() {
    const form = document.getElementById('formEnsalamento');
    const formData = new FormData(form);
    
    // Fechar modal de configuracao
    const configModal = bootstrap.Modal.getInstance(document.getElementById('modalEnsalamento'));
    if (configModal) configModal.hide();
    
    // Mostrar modal de progresso
    const progressModal = new bootstrap.Modal(document.getElementById('modalProgresso'));
    progressModal.show();
    
    // Executar ensalamento
    fetch('api/ensalamento.php?action=executar', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        progressModal.hide();
        
        if (data.success) {
            showEnsalamentoResult(data.data);
            loadDashboardData(); // Atualizar dashboard
        } else {
            showError('Erro ao executar ensalamento: ' + data.message);
        }
    })
    .catch(error => {
        progressModal.hide();
        showError('Erro na comunicacao: ' + error.message);
    });
};

function showEnsalamentoResult(result) {
    const message = `
        Ensalamento executado com sucesso!
        
        Turmas processadas: ${result.turmas_processadas || 0}
        Turmas alocadas: ${result.turmas_alocadas || 0}
        Conflitos: ${result.turmas_conflito || 0}
        Eficiencia media: ${(result.eficiencia_media || 0).toFixed(2)}%
        Tempo: ${result.tempo_processamento || 0}ms
    `;
    
    alert(message);
}

// Exportar funcoes para uso global
window.dashboardJS = {
    loadDashboardData,
    showEnsalamentoModal,
    showError,
    startAutoRefresh,
    stopAutoRefresh
};

