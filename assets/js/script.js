function calculateDays() {
    const start = document.getElementById("start_date").value;
    const end = document.getElementById("end_date").value;
    if (!start || !end) return;
    fetch(`../api/calc_days.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`)
        .then(res => res.json())
        .then(data => {
            if (data.days !== undefined) {
                document.getElementById("total_days").value = data.days;
                if (typeof window.checkBalanceWarning === 'function') {
                    window.checkBalanceWarning(data.days);
                }
            }
        });
}

// safe form submit handler (only when a form and password field exist)
var _form = document.querySelector('form');
if (_form) {
    _form.addEventListener('submit', function(e){
        var pwdField = document.querySelector('input[name="password"]');
        if (pwdField) {
            var pwd = pwdField.value || '';
            if (pwd.length > 0 && pwd.length < 6) {
                alert("Password must be at least 6 characters.");
                e.preventDefault();
            }
        }
    });
}

// toggle shadow removal on scroll to reduce heavy background shadow when scrolled
window.addEventListener('scroll', function() {
    if (window.scrollY > 20) document.body.classList.add('no-shadow');
    else document.body.classList.remove('no-shadow');
});