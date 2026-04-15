document.addEventListener('DOMContentLoaded', function () {
    const container = document.querySelector('.rene-survey-container');
    if (!container) return;

    const appDiv = document.getElementById('rene-survey-app');
    const slug   = container.getAttribute('data-slug');
    let allQuestions = [];

    try {
        allQuestions = JSON.parse(container.getAttribute('data-questions'));
    } catch (e) {
        appDiv.innerHTML = '<p class="error">Erro ao carregar o questionário.</p>';
        return;
    }

    if (!allQuestions.length) {
        appDiv.innerHTML = '<p class="empty-msg">Nenhuma pergunta encontrada para este questionário.</p>';
        return;
    }

    // ── Divide em páginas pelo marcador page_break ─────────────────────
    const pages = [];
    let buf = [];
    allQuestions.forEach(q => {
        if (q.type === 'page_break') {
            if (buf.length) { pages.push(buf); buf = []; }
        } else {
            buf.push(q);
        }
    });
    if (buf.length) pages.push(buf);

    if (!pages.length) {
        appDiv.innerHTML = '<p class="empty-msg">Nenhuma pergunta encontrada.</p>';
        return;
    }

    // Índice global para numerar as questões correto
    const realQuestions = allQuestions.filter(q => q.type !== 'page_break');

    let currentPage = 0;
    const answers   = {};   // { qid: value }

    // ── Renderiza a página atual ───────────────────────────────────────
    function renderPage(pi) {
        const qs      = pages[pi];
        const isFirst = pi === 0;
        const isLast  = pi === pages.length - 1;
        const total   = pages.length;

        let html = `<form id="rene-survey-form" class="survey-form-premium">`;

        // Indicador de progresso (só se multi-página)
        if (total > 1) {
            const pct = Math.round((pi / (total - 1)) * 100);
            html += `
            <div class="survey-progress">
                <div class="survey-progress-bar">
                    <div class="survey-progress-fill" style="width:${pct}%"></div>
                </div>
                <span class="survey-progress-label">Página ${pi + 1} de ${total}</span>
            </div>`;
        }

        // Questões
        qs.forEach(q => {
            const num = realQuestions.indexOf(q) + 1;
            html += `<div class="question-block" data-qid="${q.id}">`;
            html += `<p class="question-label"><span>${num}.</span> ${q.label}</p>`;

            if (q.type === 'multiple') {
                html += `<div class="options-group">`;
                (q.options || []).forEach((opt, oi) => {
                    const id      = `q_${q.id}_opt_${oi}`;
                    const checked = answers[q.id] === opt ? 'checked' : '';
                    html += `
                    <label class="radio-option" for="${id}">
                        <input type="radio" id="${id}" name="q_${q.id}" value="${opt}" ${checked}>
                        <span class="radio-custom"></span>
                        <span class="radio-label-text">${opt}</span>
                    </label>`;
                });
                html += `</div>`;
            } else if (q.type === 'text') {
                const saved = answers[q.id] ? escHtml(answers[q.id]) : '';
                html += `<textarea name="q_${q.id}" class="text-input-premium" rows="3" placeholder="Sua resposta...">${saved}</textarea>`;
            }

            html += `</div>`;
        });

        // Navegação
        html += `<div class="form-navigation">`;
        if (!isFirst) {
            html += `<button type="button" class="btn-nav btn-back">← Voltar</button>`;
        } else {
            html += `<span></span>`;
        }
        if (isLast) {
            html += `<button type="submit" class="btn-premium btn-submit">Enviar Questionário</button>`;
        } else {
            html += `<button type="button" class="btn-premium btn-next">Próximo →</button>`;
        }
        html += `</div>`;

        html += `<div id="survey-message" class="survey-message hidden"></div>`;
        html += `</form>`;

        appDiv.innerHTML = html;

        const form = document.getElementById('rene-survey-form');

        // Salva respostas em tempo real
        form.querySelectorAll('input[type="radio"]').forEach(r => {
            r.addEventListener('change', function () {
                answers[this.name.replace('q_', '')] = this.value;
                // Remove erro ao responder
                this.closest('.question-block').classList.remove('question-error');
            });
        });
        form.querySelectorAll('textarea').forEach(ta => {
            ta.addEventListener('input', function () {
                answers[this.name.replace('q_', '')] = this.value;
                this.closest('.question-block').classList.remove('question-error');
            });
        });

        // Voltar
        const btnBack = form.querySelector('.btn-back');
        if (btnBack) {
            btnBack.addEventListener('click', () => {
                currentPage--;
                renderPage(currentPage);
            });
        }

        // Próximo — valida antes de avançar
        const btnNext = form.querySelector('.btn-next');
        if (btnNext) {
            btnNext.addEventListener('click', () => {
                if (!validatePage(qs, form)) return;
                currentPage++;
                renderPage(currentPage);
            });
        }

        // Enviar (última página)
        if (isLast) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!validatePage(qs, form)) return;

                const btn = form.querySelector('.btn-submit');
                const msgDiv = document.getElementById('survey-message');
                btn.disabled = true;
                btn.textContent = 'Enviando...';

                const payload = new URLSearchParams();
                payload.append('action', 'rene_submit_survey');
                payload.append('nonce', ReneSurveysData.nonce);
                payload.append('slug', slug);
                payload.append('answers', JSON.stringify(answers));

                fetch(ReneSurveysData.ajax_url, {
                    method: 'POST',
                    body: payload,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                })
                .then(r => r.json())
                .then(data => {
                    msgDiv.classList.remove('hidden', 'error', 'success');
                    if (data.success) {
                        msgDiv.classList.add('success');
                        msgDiv.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> ${data.data.message}`;
                        setTimeout(() => { form.style.display = 'none'; }, 500);
                    } else {
                        msgDiv.classList.add('error');
                        msgDiv.innerHTML = data.data.message || 'Erro desconhecido.';
                        btn.disabled = false;
                        btn.textContent = 'Enviar Questionário';
                    }
                })
                .catch(() => {
                    msgDiv.classList.remove('hidden');
                    msgDiv.classList.add('error');
                    msgDiv.innerHTML = 'Erro na comunicação com o servidor.';
                    btn.disabled = false;
                    btn.textContent = 'Enviar Questionário';
                });
            });
        }
    }

    // ── Validação da página ────────────────────────────────────────────
    function validatePage(qs, form) {
        let valid = true;
        qs.forEach(q => {
            const block = form.querySelector(`[data-qid="${q.id}"]`);
            let answered = false;
            if (q.type === 'multiple') {
                answered = !!answers[q.id];
            } else if (q.type === 'text') {
                answered = !!(answers[q.id] && answers[q.id].trim());
            }
            if (!answered) {
                block.classList.add('question-error');
                valid = false;
            }
        });
        if (!valid) {
            // Scrolla para o primeiro erro
            const first = form.querySelector('.question-error');
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return valid;
    }

    function scrollToForm() {
        window.scrollTo({ top: container.offsetTop - 32, behavior: 'smooth' });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    renderPage(0);
});
