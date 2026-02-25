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

document.querySelector('form').addEventListener('submit', function(e){
    const pwd = document.querySelector('input[name="password"]').value;
    if(pwd.length < 6){
        alert("Password must be at least 6 characters.");
        e.preventDefault();
    }
});