document.addEventListener('DOMContentLoaded', function() {
    const container = document.querySelector('.rene-survey-container');
    if (!container) return;

    const appDiv = document.getElementById('rene-survey-app');
    const slug = container.getAttribute('data-slug');
    let questions = [];
    
    try {
        questions = JSON.parse(container.getAttribute('data-questions'));
    } catch(e) {
        console.error("Erro ao ler as questões:", e);
        appDiv.innerHTML = '<p class="error">Erro ao carregar o questionário.</p>';
        return;
    }

    if (questions.length === 0) {
        appDiv.innerHTML = '<p class="empty-msg">Nenhuma pergunta encontrada para este questionário.</p>';
        return;
    }

    // Builder do Formulário
    let html = `<form id="rene-survey-form" class="survey-form-premium">`;
    
    questions.forEach((q, index) => {
        html += `<div class="question-block">`;
        html += `  <label class="question-label"><span>${index + 1}.</span> ${q.label}</label>`;
        
        if (q.type === 'multiple') {
            html += `<div class="options-group">`;
            q.options.forEach((opt, optIndex) => {
                const optId = `q_${q.id}_opt_${optIndex}`;
                html += `
                    <div class="radio-option">
                        <input type="radio" id="${optId}" name="q_${q.id}" value="${opt}" required>
                        <label for="${optId}">${opt}</label>
                    </div>
                `;
            });
            html += `</div>`;
        } 
        else if (q.type === 'text') {
            html += `<textarea name="q_${q.id}" class="text-input-premium" rows="3" placeholder="Sua resposta..."></textarea>`;
        }
        
        html += `</div>`;
    });

    html += `
        <div class="form-actions">
            <button type="submit" class="btn-premium">Enviar Questionário</button>
        </div>
        <div id="survey-message" class="survey-message hidden"></div>
    </form>`;

    appDiv.innerHTML = html;

    // Handle Submission
    const form = document.getElementById('rene-survey-form');
    const msgDiv = document.getElementById('survey-message');

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(form);
        const answers = {};

        formData.forEach((value, key) => {
            // key removes 'q_' prefix
            let cleanKey = key.replace('q_', '');
            answers[cleanKey] = value;
        });

        // Setup AJAX using WordPress localization variables
        const payload = new URLSearchParams();
        payload.append('action', 'rene_submit_survey');
        payload.append('nonce', ReneSurveysData.nonce);
        payload.append('slug', slug);
        payload.append('answers', JSON.stringify(answers));

        const btn = form.querySelector('button[type="submit"]');
        const oldText = btn.innerHTML;
        btn.innerHTML = 'Enviando...';
        btn.disabled = true;

        fetch(ReneSurveysData.ajax_url, {
            method: 'POST',
            body: payload,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            }
        })
        .then(response => response.json())
        .then(data => {
            msgDiv.classList.remove('hidden', 'error', 'success');
            if(data.success) {
                msgDiv.classList.add('success');
                msgDiv.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> ${data.data.message}`;
                form.reset();
                setTimeout(() => {
                    form.style.display = 'none';
                }, 2000);
            } else {
                msgDiv.classList.add('error');
                msgDiv.innerHTML = data.data.message || 'Erro desconhecido.';
            }
        })
        .catch(err => {
            console.error("AJAX Error:", err);
            msgDiv.classList.remove('hidden');
            msgDiv.classList.add('error');
            msgDiv.innerHTML = 'Erro na comunicação com o servidor.';
        })
        .finally(() => {
            btn.innerHTML = oldText;
            btn.disabled = false;
        });
    });
});
