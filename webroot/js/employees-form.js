document.addEventListener('DOMContentLoaded', function () {
    var contractType = document.getElementById('contract-type');
    var orgWrapper = document.getElementById('org-temporal-wrapper');
    if (!contractType || !orgWrapper) {
        return;
    }
    function toggleOrg() {
        orgWrapper.style.display = contractType.value === 'OBRA O LABOR DETERMINADA' ? '' : 'none';
    }
    contractType.addEventListener('change', toggleOrg);
    toggleOrg();
});
