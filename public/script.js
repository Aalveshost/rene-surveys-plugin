document.addEventListener('DOMContentLoaded', function () {
    const container = document.querySelector('.rene-survey-container');
    if (!container) return;

    const appDiv = document.getElementById('rene-survey-app');
    const slug   = container.getAttribute('data-slug');
    let allQuestions = [];
    let config = {};

    try {
        allQuestions = JSON.parse(container.getAttribute('data-questions'));
    } catch (e) {
        appDiv.innerHTML = '<p class="error">Erro ao carregar o questionário.</p>';
        return;
    }

    try {
        config = JSON.parse(container.getAttribute('data-config') || '{}');
    } catch (e) { config = {}; }

    const hasIntro = !!(config.title || config.subtitle || config.description);
    let onIntro = hasIntro;

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

    // ── Página de Apresentação ─────────────────────────────────────────
    function renderIntroPage() {
        const logoRightClass = config.logo_right_cover ? 'survey-intro-logo survey-intro-logo--cover' : 'survey-intro-logo';
        const logoLeft  = config.logo_left  ? `<div class="survey-intro-logo-col"><img src="${escHtml(config.logo_left)}" alt="Logo" class="survey-intro-logo"></div>` : `<div class="survey-intro-logo-col"></div>`;
        const logoRight = config.logo_right ? `<div class="survey-intro-logo-col right"><img src="${escHtml(config.logo_right)}" alt="Logo cliente" class="${logoRightClass}"></div>` : `<div class="survey-intro-logo-col right"></div>`;

        let instHtml = '';
        if (config.instructions && config.instructions.length) {
            instHtml = `<div class="survey-intro-instructions">
                <strong>Instruções Importantes:</strong>
                <ul>${config.instructions.map(i => `<li>${escHtml(i)}</li>`).join('')}</ul>
            </div>`;
        }

        appDiv.innerHTML = `
        <div class="survey-intro">
            <div class="survey-intro-header">
                <div class="survey-intro-logos">
                    ${logoLeft}
                    ${logoRight}
                </div>
                ${config.title ? `<h1 class="survey-intro-title">${escHtml(config.title)}</h1>` : ''}
            </div>
            <div class="survey-intro-body">
                ${config.subtitle    ? `<h2 class="survey-intro-subtitle">${escHtml(config.subtitle)}</h2>` : ''}
                ${config.description ? `<div class="survey-intro-desc">${escHtmlWithLineBreaks(config.description)}</div>` : ''}
                ${instHtml}
                ${config.period      ? `<p class="survey-intro-period">Período de aplicação da pesquisa: ${escHtml(config.period)}</p>` : ''}
                <div class="survey-intro-footer">
                    <button class="btn-premium" id="fswp-btn-seguinte">Seguinte</button>
                </div>
            </div>
        </div>`;

        document.getElementById('fswp-btn-seguinte').addEventListener('click', () => {
            onIntro = false;
            if (pages.length) {
                renderPage(0);
            } else {
                appDiv.innerHTML = '<p class="empty-msg">Nenhuma pergunta encontrada.</p>';
            }
        });
    }

    if (buf.length) pages.push(buf);

    if (!pages.length && !onIntro) {
        appDiv.innerHTML = '<p class="empty-msg">Nenhuma pergunta encontrada.</p>';
        return;
    }

    if (onIntro) {
        renderIntroPage();
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
                btn.innerHTML = '<span class="btn-spinner"></span> Enviando…';

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
                    if (data.success) {
                        // Fade out form
                        form.style.transition = 'opacity .35s ease';
                        form.style.opacity = '0';
                        setTimeout(() => {
                            appDiv.innerHTML = `
                            <div class="survey-success-screen">
                                <div class="survey-success-icon">
                                    <svg viewBox="0 0 52 52" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <circle class="success-circle" cx="26" cy="26" r="25" stroke="#04d361" stroke-width="2" fill="none"/>
                                        <polyline class="success-check" points="14,27 22,35 38,18" stroke="#04d361" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    </svg>
                                </div>
                                <h2 class="survey-success-title">Pesquisa enviada!</h2>
                                <p class="survey-success-sub">Obrigado pela sua participação. Suas respostas foram registradas com sucesso e serão analisadas pela nossa equipe.</p>
                                <div class="survey-success-badge">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                    Resposta registrada com segurança
                                </div>
                            </div>`;
                        }, 380);
                    } else {
                        msgDiv.classList.remove('hidden');
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

    function escHtmlWithLineBreaks(s) {
        // Normaliza tanto \n real quanto \n literal (legado)
        return String(s).replace(/\\n/g, '\n').split('\n').map(line => escHtml(line)).join('<br>');
    }

    if (!onIntro && pages.length) {
        renderPage(0);
    }
});
