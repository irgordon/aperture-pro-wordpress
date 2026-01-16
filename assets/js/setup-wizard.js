(function () {
    let step = 1;
    const steps = document.querySelectorAll('.ap-step');
    const progress = document.querySelector('.ap-progress-fill');

    function updateUI() {
        steps.forEach(s => {
            s.style.display = parseInt(s.dataset.step) === step ? 'block' : 'none';
        });

        progress.style.width = ((step - 1) / (steps.length - 1)) * 100 + '%';

        document.getElementById('ap-prev').style.display = step === 1 ? 'none' : 'inline-block';
        document.getElementById('ap-next').style.display = step === steps.length ? 'none' : 'inline-block';
        document.getElementById('ap-finish').style.display = step === steps.length ? 'inline-block' : 'none';
    }

    document.getElementById('ap-next').onclick = () => {
        if (step < steps.length) step++;
        updateUI();
    };

    document.getElementById('ap-prev').onclick = () => {
        if (step > 1) step--;
        updateUI();
    };

    updateUI();
})();
