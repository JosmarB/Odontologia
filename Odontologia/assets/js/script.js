document.addEventListener('DOMContentLoaded', function() {
    // Validación de formularios
    const forms = document.querySelectorAll('form.needs-validation');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Mostrar/ocultar campos condicionales
    const edadInput = document.getElementById('edad');
    const representanteGroup = document.getElementById('representante-group');
    
    if (edadInput && representanteGroup) {
        const toggleRepresentante = () => {
            representanteGroup.style.display = parseInt(edadInput.value) < 18 ? 'block' : 'none';
        };
        
        edadInput.addEventListener('change', toggleRepresentante);
        toggleRepresentante(); // Ejecutar al cargar
    }

    // Confirmación antes de eliminar
    const deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            if (!confirm('¿Está seguro que desea eliminar este registro?')) {
                e.preventDefault();
            }
        });
    });
});