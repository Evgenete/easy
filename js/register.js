document.getElementById('register-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const name = document.getElementById('register-name').value;
    const email = document.getElementById('register-email').value;
    const phone = document.getElementById('register-phone').value;
    const password = document.getElementById('register-password').value;
    const confirmPassword = document.getElementById('register-confirm-password').value;
    const agreeTerms = document.getElementById('agree-terms').checked;
    
    if (password !== confirmPassword) {
        alert('Пароли не совпадают!');
        return;
    }
    
    if (!agreeTerms) {
        alert('Необходимо согласиться с условиями использования');
        return;
    }
    
    console.log('Регистрация:', { name, email, phone, password });
    
    // В реальном приложении здесь был бы AJAX-запрос к серверу
    setTimeout(() => {
        alert('Регистрация прошла успешно!');
        window.location.href = 'login.html';
    }, 1000);
});

// Индикатор сложности пароля
document.getElementById('register-password').addEventListener('input', function() {
    const password = this.value;
    const strengthBar = document.getElementById('password-strength');
    
    let strength = 0;
    if (password.length >= 8) strength++;
    if (/[a-z]/.test(password)) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    strengthBar.className = 'password-strength';
    if (strength > 0) {
        if (strength <= 2) {
            strengthBar.classList.add('weak');
        } else if (strength <= 4) {
            strengthBar.classList.add('medium');
        } else {
            strengthBar.classList.add('strong');
        }
    }
});

// Обработка социальных кнопок
document.querySelectorAll('.social-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const provider = this.classList.contains('vk') ? 'VK' : 
                        this.classList.contains('google') ? 'Google' : 'Yandex';
        console.log(`Регистрация через ${provider}`);
        // Здесь будет логика OAuth
    });
});