function calculateDays(onComplete) {
    const startField = document.getElementById("start_date");
    const endField = document.getElementById("end_date");
    const totalField = document.getElementById("total_days");
    const feedbackField = document.getElementById("date-range-feedback");

    if (!startField || !endField || !totalField) {
        if (typeof onComplete === 'function') onComplete();
        return;
    }

    const start = startField.value;
    const end = endField.value;

    const setFeedback = (message, isError = false) => {
        if (!feedbackField) return;
        feedbackField.textContent = message || '';
        feedbackField.style.color = isError ? '#b91c1c' : '#6b7280';
    };

    if (start) {
        endField.min = start;
    } else {
        endField.removeAttribute('min');
    }

    if (!start || !end) {
        totalField.value = '';
        setFeedback('Select both start and end dates to compute deductible working days.');
        if (typeof onComplete === 'function') onComplete();
        return;
    }

    if (end < start) {
        totalField.value = '0';
        setFeedback('End date cannot be earlier than start date.', true);
        if (typeof window.checkBalanceWarning === 'function') {
            window.checkBalanceWarning(0);
        }
        if (typeof onComplete === 'function') onComplete();
        return;
    }

    fetch(`../api/calc_days.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`)
        .then(res => res.json())
        .then(data => {
            const days = Number.isFinite(Number(data.days)) ? Number(data.days) : 0;
            totalField.value = String(days);

            if (data.valid === false) {
                setFeedback(data.message || 'Please enter a valid date range.', true);
            } else if (days <= 0) {
                setFeedback(data.message || 'The selected range contains no deductible working days.', true);
            } else {
                const holidayDays = Number(data.holiday_days || 0);
                const weekendDays = Number(data.weekend_days || 0);
                let summary = `${days} working day(s)`;
                const exclusions = [];
                if (weekendDays > 0) exclusions.push(`${weekendDays} weekend day(s)`);
                if (holidayDays > 0) exclusions.push(`${holidayDays} holiday(s)`);
                if (exclusions.length > 0) {
                    summary += ` after excluding ${exclusions.join(' and ')}`;
                }
                setFeedback(summary);
            }

            if (typeof window.checkBalanceWarning === 'function') {
                window.checkBalanceWarning(days);
            }
        })
        .catch(() => {
            totalField.value = '';
            setFeedback('Unable to calculate deductible days right now. Please try again.', true);
            if (typeof window.checkBalanceWarning === 'function') {
                window.checkBalanceWarning(0);
            }
        })
        .finally(() => {
            if (typeof onComplete === 'function') onComplete();
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

function initCollapsibleSections() {
    document.querySelectorAll('.collapsible-card').forEach((card) => {
        var header = card.querySelector('.collapsible-header');
        var body = card.querySelector('.collapsible-body');
        var toggle = card.querySelector('.collapsible-toggle');
        if (!header || !body || !toggle) return;

        var setExpanded = function(expanded) {
            body.classList.toggle('expanded', expanded);
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            toggle.textContent = expanded ? '▾' : '▸';
        };

        setExpanded(true);

        header.addEventListener('click', function() {
            var expanded = body.classList.contains('expanded');
            setExpanded(!expanded);
        });

        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            var expanded = body.classList.contains('expanded');
            setExpanded(!expanded);
        });
    });
}

document.addEventListener('DOMContentLoaded', initCollapsibleSections);