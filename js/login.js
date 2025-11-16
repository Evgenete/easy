document.getElementById('login-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;
    const rememberMe = document.getElementById('remember-me').checked;
    
    console.log('Вход с:', { email, password, rememberMe });
    
    // В реальном приложении здесь был бы AJAX-запрос к серверу
    // Имитация успешного входа
    setTimeout(() => {
        alert('Вход выполнен успешно!');
        window.location.href = 'index.html';
    }, 1000);
});

// Обработка социальных кнопок
document.querySelectorAll('.social-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const provider = this.classList.contains('vk') ? 'VK' : 
                        this.classList.contains('google') ? 'Google' : 'Yandex';
        console.log(`Вход через ${provider}`);
        // Здесь будет логика OAuth
    });
});