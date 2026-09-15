/**
 * JavaScript da Plataforma MikroTik Pay
 */

document.addEventListener('DOMContentLoaded', function () {
    console.log('MikroTik Pay JS carregado.');
});

/**
 * Função para copiar o PIX Copia e Cola com feedback visual
 */
function copiarPix(elementId) {
    const pixInput = document.getElementById(elementId);
    if (!pixInput) return;

    const pixText = pixInput.innerText || pixInput.value;

    navigator.clipboard.writeText(pixText).then(function () {
        const btn = document.getElementById('btnCopiarPix');
        if (btn) {
            const originalText = btn.innerHTML;
            btn.innerHTML = '✅ PIX COPIADO!';
            btn.style.backgroundColor = '#10b981';

            setTimeout(function () {
                btn.innerHTML = originalText;
                btn.style.backgroundColor = '';
            }, 3000);
        }
    }).catch(function (err) {
        alert('Erro ao copiar código PIX. Por favor selecione e copie manualmente.');
    });
}

/**
 * Polling para verificar se o pagamento foi concluído em tempo real
 */
function iniciarPollingPagamento(faturaId) {
    const interval = setInterval(function () {
        fetch(`../webhook/check_status.php?id=${faturaId}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'pago') {
                    clearInterval(interval);
                    const statusContainer = document.getElementById('statusPagamentoContainer');
                    if (statusContainer) {
                        statusContainer.innerHTML = `
                            <div class="alert alert-success text-center p-4 rounded-3 shadow">
                                <h3 class="fw-bold mb-2">🎉 Pagamento Confirmado!</h3>
                                <p class="mb-0">Sua fatura foi quitada com sucesso. Seu acesso foi renovado!</p>
                            </div>
                        `;
                    }
                }
            })
            .catch(err => console.log('Aguardando confirmação do PIX...'));
    }, 4000);
}
