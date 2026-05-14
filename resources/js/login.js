(function () {
    var toggle = document.getElementById('password-toggle');
    var input = document.getElementById('password-input');
    if (!toggle || !input) {
        return;
    }

    toggle.addEventListener('click', function () {
        var isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        var icon = toggle.querySelector('i');
        if (icon) {
            icon.setAttribute('data-lucide', isPassword ? 'eye-off' : 'eye');
            if (window.lucide && window.lucide.createIcons) {
                window.lucide.createIcons();
            }
        }
    });
})();
