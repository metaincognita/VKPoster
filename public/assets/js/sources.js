// Server validation returns through a redirect; put keyboard focus on the first invalid source field.
document.addEventListener('DOMContentLoaded', function () {
    var field = document.querySelector('[data-source-form] [aria-invalid="true"]');
    if (field) { field.focus(); }
});
