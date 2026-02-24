function calculateDays() {
    const start = new Date(document.getElementById("start_date").value);
    const end = new Date(document.getElementById("end_date").value);

    if (start && end) {
        const diff = (end - start) / (1000 * 3600 * 24) + 1;
        document.getElementById("total_days").value = diff;
    }
}

document.querySelector('form').addEventListener('submit', function(e){
    const pwd = document.querySelector('input[name="password"]').value;
    if(pwd.length < 6){
        alert("Password must be at least 6 characters.");
        e.preventDefault();
    }
});