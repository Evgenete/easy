let currentStep = 1;
let countdown = 60;
let countdownInterval;

// Переход между шагами
function goToStep(step) {
    // Скрываем все шаги
    document.getElementById('step1-content').style.display = 'none';
    document.getElementById('step2-content').style.display = 'none';
    document.getElementById('step3-content').style.display = 'none';
    document.getElementById('success-content').style.display = 'none';
    
    // Сбрасываем активные классы шагов
    document.querySelectorAll('.step').forEach(s => {
        s.classList.remove('active', 'completed');
    });
    
    // Показываем нужный шаг
    if (step === 1) {
        document.getElementById('step1-content').style.display = 'block';
        document.getElementById('step-1').classList.add('active');
    } else if (step === 2) {
        document.getElementById('step2-content').style.display = 'block';
        document.getElementById('step-1').classList.add('completed');
        document.getElementById('step-2').classList.add('active');
        startCountdown();
    } else if (step === 3) {
        document.getElementById('step3-content').style.display = 'block';
        document.getElementById('step-1').classList.add('completed');
        document.getElementById('step-2').classList.add('completed');
        document.getElementById('step-3').classList.add('active');
    } else if (step === 4) {
        document.getElementById('success-content').style.display = 'block';
        document.querySelectorAll('.step').forEach(s => {
            s.classList.add('completed');
        });
    }
    
    currentStep = step;
}

// Таймер для повторной отправки кода
function startCountdown() {
    countdown = 60;
    const countdownElement = document.getElementById('countdown');
    const timerText = document.getElementById('timer-text');
    const resendLink = document.getElementById('resend-link');
    
    timerText.style.display = 'block';
    resendLink.style.display = 'none';
    
    clearInterval(countdownInterval);
    countdownInterval = setInterval(() => {
        countdown--;
        countdownElement.textContent = countdown;
        
        if (countdown <= 0) {
            clearInterval(countdownInterval);
            timerText.style.display = 'none';
            resendLink.style.display = 'inline';
        }
    }, 1000);
}

// Автопереход между полями кода
document.querySelectorAll('.code-input').forEach((input, index, inputs) => {
    input.addEventListener('input', (e) => {
        if (e.target.value.length === 1 && index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
    });
    
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && e.target.value.length === 0 && index > 0) {
            inputs[index - 1].focus();
        }
    });
});

// Обработка формы email
document.getElementById('email-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const email = document.getElementById('recovery-email').value;
    console.log('Отправка кода на:', email);
    
    // Имитация отправки кода
    setTimeout(() => {
        goToStep(2);
    }, 1000);
});

// Обработка формы кода
document.getElementById('code-form').addEventListener('submit', function(e) {
    e.preventDefault();
    console.log('Подтверждение кода');
    
    // Имитация проверки кода
    setTimeout(() => {
        goToStep(3);
    }, 1000);
});

// Обработка формы нового пароля
document.getElementById('password-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-new-password').value;
    
    if (newPassword !== confirmPassword) {
        alert('Пароли не совпадают!');
        return;
    }
    
    console.log('Установка нового пароля');
    
    // Имитация смены пароля
    setTimeout(() => {
        goToStep(4);
    }, 1000);
});

// Повторная отправка кода
document.getElementById('resend-link').addEventListener('click', function(e) {
    e.preventDefault();
    const email = document.getElementById('recovery-email').value;
    console.log('Повторная отправка кода на:', email);
    startCountdown();
});

// Инициализация первого шага
goToStep(1);