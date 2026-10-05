// Server validation returns through a redirect; put keyboard focus on the first invalid source field.
document.addEventListener('DOMContentLoaded', function () {
    var field = document.querySelector('[data-source-form] [aria-invalid="true"]');
    if (field) { field.focus(); }
    document.querySelectorAll('[data-text-processing]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); button.classList.add('btn-loading'); }
        });
    });
});
