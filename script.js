// Global variables
let currentUser = null;
let currentRequestId = null;
let categories = [];
let updateInterval = null;

// Bootstrap Modal instances
let requestDetailsModal = null;
let employeeModal = null;

// DOM Elements
const authForms = document.getElementById('authForms');
const mainContent = document.getElementById('mainContent');
const citizenInterface = document.getElementById('citizenInterface');
const employeeInterface = document.getElementById('employeeInterface');
const adminInterface = document.getElementById('adminInterface');
const userName = document.getElementById('userName');

// Initialize the application
document.addEventListener('DOMContentLoaded', () => {
    requestDetailsModal = new bootstrap.Modal(document.getElementById('requestDetailsModal'));
    employeeModal = new bootstrap.Modal(document.getElementById('employeeModal'));

    // Event listeners for forms
    document.getElementById('loginForm').addEventListener('submit', handleLogin);
    document.getElementById('registerForm').addEventListener('submit', handleRegister);
    document.getElementById('newRequestForm').addEventListener('submit', handleNewRequest);
    document.getElementById('messageForm').addEventListener('submit', handleSendMessage);
    document.getElementById('employeeForm').addEventListener('submit', handleEmployeeForm);
    document.getElementById('requestFilter').addEventListener('change', loadCitizenRequests);

    // Load categories for the new request form
    loadCategories();
});

// API Functions
async function apiCall(action, data = {}) {
    try {
        const response = await fetch('api.php?action=' + action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ ...data, action }),
        });
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        return { success: false, error: 'Network error' };
    }
}

// Authentication Functions
async function handleLogin(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const response = await apiCall('login', {
        username: formData.get('username'),
        password: formData.get('password'),
    });

    if (response.success) {
        currentUser = response.data;
        showInterface();
        startUpdateInterval();
    } else {
        alert(response.error || 'Login failed');
    }
}

async function handleRegister(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const response = await apiCall('register', Object.fromEntries(formData));

    if (response.success) {
        alert('Registration successful! Please login.');
        e.target.reset();
    } else {
        alert(response.error || 'Registration failed');
    }
}

function logout() {
    currentUser = null;
    clearInterval(updateInterval);
    authForms.classList.remove('hidden');
    mainContent.classList.add('hidden');
    document.getElementById('loginForm').reset();
}

// Interface Management
function showInterface() {
    authForms.classList.add('hidden');
    mainContent.classList.remove('hidden');
    userName.textContent = currentUser.name;

    citizenInterface.classList.add('hidden');
    employeeInterface.classList.add('hidden');
    adminInterface.classList.add('hidden');

    switch (currentUser.role) {
        case 'citizen':
            citizenInterface.classList.remove('hidden');
            break;
        case 'employee':
            employeeInterface.classList.remove('hidden');
            break;
        case 'admin':
            adminInterface.classList.remove('hidden');
            break;
    }
}

function startUpdateInterval() {
    if (updateInterval) clearInterval(updateInterval);
    updateInterval = setInterval(() => {
        switch (currentUser.role) {
            case 'citizen':
                loadCitizenRequests();
                break;
            case 'employee':
                loadDepartmentRequests();
                break;
            case 'admin':
                loadEmployees();
                loadCitizens();
                break;
        }
    }, 5000);

    // Initial load
    switch (currentUser.role) {
        case 'citizen':
            loadCitizenRequests();
            break;
        case 'employee':
            loadDepartmentRequests();
            break;
        case 'admin':
            loadEmployees();
            loadCitizens();
            break;
    }
}

// Category Management
async function loadCategories() {
    const response = await apiCall('get_categories');
    if (response.success) {
        categories = response.data.categories;
        const categorySelect = document.querySelector('select[name="category"]');
        const subcategorySelect = document.querySelector('select[name="subcategory"]');
        
        categorySelect.innerHTML = '<option value="">Select Category</option>';
        categories.forEach(category => {
            categorySelect.innerHTML += `<option value="${category.id}">${category.name}</option>`;
        });

        categorySelect.addEventListener('change', () => {
            const selectedCategory = categories.find(c => c.id === categorySelect.value);
            subcategorySelect.innerHTML = '<option value="">Select Subcategory (Optional)</option>';
            if (selectedCategory) {
                const subcategories = JSON.parse(selectedCategory.subcategories);
                subcategories.forEach(sub => {
                    subcategorySelect.innerHTML += `<option value="${sub.id}">${sub.name}</option>`;
                });
            }
        });
    }
}

// Request Management
async function handleNewRequest(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const response = await apiCall('create_request', {
        title: formData.get('title'),
        description: formData.get('description'),
        address: formData.get('address'),
        category_id: formData.get('category'),
        subcategory_id: formData.get('subcategory'),
        user_id: currentUser.id,
        assigned_department_id: 1 // This should be determined based on the category
    });

    if (response.success) {
        alert('Request created successfully!');
        e.target.reset();
        loadCitizenRequests();
    } else {
        alert(response.error || 'Failed to create request');
    }
}

async function loadCitizenRequests() {
    const filter = document.getElementById('requestFilter').value;
    const response = await apiCall('get_requests_citizen', {
        user_id: currentUser.id,
        filter: filter
    });

    if (response.success) {
        const requestsList = document.getElementById('requestsList');
        requestsList.innerHTML = response.data.map(request => `
            <div class="card request-card">
                <div class="card-body">
                    <h5 class="card-title">${request.title}</h5>
                    <p class="card-text">${request.description}</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="badge bg-${getStatusBadgeColor(request.status)}">${request.status}</span>
                        <button class="btn btn-primary btn-sm" onclick="showRequestDetails(${request.id})">
                            View Details
                        </button>
                    </div>
                </div>
            </div>
        `).join('');
    }
}

async function showRequestDetails(requestId) {
    currentRequestId = requestId;
    const response = await apiCall('get_request_details', { request_id: requestId });

    if (response.success) {
        const request = response.data;
        document.getElementById('requestDetails').innerHTML = `
            <h4>${request.title}</h4>
            <p>${request.description}</p>
            <p><strong>Status:</strong> ${request.status}</p>
            <p><strong>Category:</strong> ${request.category_name}</p>
            ${request.subcategory_name ? `<p><strong>Subcategory:</strong> ${request.subcategory_name}</p>` : ''}
            <p><strong>Address:</strong> ${request.address}</p>
            <p><strong>Date:</strong> ${new Date(request.date).toLocaleString()}</p>
        `;

        const messagesList = document.getElementById('messagesList');
        messagesList.innerHTML = request.messages.map(msg => `
            <div class="message ${msg.role}">
                <strong>${msg.role === 'citizen' ? request.citizen_name : 'Employee'}:</strong>
                <p>${msg.message_text}</p>
                <small>${new Date(msg.timestamp).toLocaleString()}</small>
            </div>
        `).join('');

        requestDetailsModal.show();
    }
}

async function handleSendMessage(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const response = await apiCall('send_message', {
        request_id: currentRequestId,
        sender_id: currentUser.id,
        text: formData.get('message')
    });

    if (response.success) {
        e.target.reset();
        showRequestDetails(currentRequestId);
    } else {
        alert(response.error || 'Failed to send message');
    }
}

// Employee Management
async function loadEmployees() {
    const response = await apiCall('get_employees');
    if (response.success) {
        const employeesList = document.getElementById('employeesList');
        employeesList.innerHTML = response.data.map(employee => `
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">${employee.name}</h5>
                    <p class="card-text">Departments: ${employee.department}</p>
                    <div class="btn-group">
                        <button class="btn btn-primary btn-sm" onclick="editEmployee(${employee.id})">Edit</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteEmployee(${employee.id})">Delete</button>
                    </div>
                </div>
            </div>
        `).join('');
    }
}

function showAddEmployeeModal() {
    document.getElementById('employeeForm').reset();
    document.querySelector('#employeeForm input[name="id"]').value = '';
    employeeModal.show();
}

async function editEmployee(id) {
    const employees = await apiCall('get_employees');
    if (employees.success) {
        const employee = employees.data.find(e => e.id === id);
        if (employee) {
            const form = document.getElementById('employeeForm');
            form.elements['id'].value = employee.id;
            form.elements['name'].value = employee.name;
            form.elements['username'].value = employee.username;
            form.elements['departments'].value = employee.department.split(', ');
            employeeModal.show();
        }
    }
}

async function handleEmployeeForm(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const id = formData.get('id');
    const data = {
        name: formData.get('name'),
        username: formData.get('username'),
        password: formData.get('password'),
        departments: Array.from(formData.getAll('departments'))
    };

    const response = await apiCall(id ? 'edit_employee' : 'add_employee', { ...data, id });

    if (response.success) {
        employeeModal.hide();
        loadEmployees();
    } else {
        alert(response.error || 'Operation failed');
    }
}

async function deleteEmployee(id) {
    if (confirm('Are you sure you want to delete this employee?')) {
        const response = await apiCall('delete_employee', { id });
        if (response.success) {
            loadEmployees();
        } else {
            alert(response.error || 'Failed to delete employee');
        }
    }
}

// Citizen Management
async function loadCitizens() {
    const response = await apiCall('get_citizens');
    if (response.success) {
        const citizensList = document.getElementById('citizensList');
        citizensList.innerHTML = response.data.map(citizen => `
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">${citizen.name}</h5>
                    <p class="card-text">Username: ${citizen.username}</p>
                    <button class="btn btn-${citizen.banned ? 'success' : 'warning'} btn-sm"
                            onclick="toggleBanCitizen(${citizen.id})">
                        ${citizen.banned ? 'Unban' : 'Ban'} User
                    </button>
                </div>
            </div>
        `).join('');
    }
}

async function toggleBanCitizen(id) {
    const response = await apiCall('toggle_ban_citizen', { id });
    if (response.success) {
        loadCitizens();
    } else {
        alert(response.error || 'Failed to update user status');
    }
}

// Utility Functions
function getStatusBadgeColor(status) {
    switch (status) {
        case 'new': return 'primary';
        case 'in_progress': return 'warning';
        case 'completed': return 'success';
        default: return 'secondary';
    }
}