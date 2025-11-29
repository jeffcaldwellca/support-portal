// Main JavaScript functionality for Support Portal
'use strict';

// Global variables
let autosaveTimer;
let formValidationTimer;

// Utility functions
const Utils = {
    // Show loading overlay
    showLoading: function() {
        document.getElementById('loadingOverlay').classList.remove('d-none');
    },

    // Hide loading overlay
    hideLoading: function() {
        document.getElementById('loadingOverlay').classList.add('d-none');
    },

    // Show toast notification
    showToast: function(message, type = 'info', duration = 5000) {
        const toastContainer = document.querySelector('.toast-container');
        const toastId = 'toast-' + Date.now();
        
        const toastHTML = `
            <div id="${toastId}" class="toast toast-${type}" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <i class="bi bi-${this.getToastIcon(type)} me-2"></i>
                    <strong class="me-auto">${this.getToastTitle(type)}</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHTML);
        
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { delay: duration });
        toast.show();
        
        // Remove toast element after it's hidden
        toastElement.addEventListener('hidden.bs.toast', function() {
            toastElement.remove();
        });
    },

    getToastIcon: function(type) {
        const icons = {
            success: 'check-circle-fill',
            error: 'exclamation-triangle-fill',
            warning: 'exclamation-triangle-fill',
            info: 'info-circle-fill'
        };
        return icons[type] || 'info-circle-fill';
    },

    getToastTitle: function(type) {
        const titles = {
            success: 'Success',
            error: 'Error',
            warning: 'Warning',
            info: 'Information'
        };
        return titles[type] || 'Notification';
    },

    // Format file size
    formatFileSize: function(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    // Debounce function
    debounce: function(func, wait, immediate) {
        let timeout;
        return function executedFunction() {
            const context = this;
            const args = arguments;
            const later = function() {
                timeout = null;
                if (!immediate) func.apply(context, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(context, args);
        };
    },

    // API request helper
    apiRequest: async function(url, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        const config = { ...defaultOptions, ...options };
        
        try {
            const response = await fetch(url, config);
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || `HTTP error! status: ${response.status}`);
            }
            
            return data;
        } catch (error) {
            console.error('API request failed:', error);
            throw error;
        }
    }
};

// Authentication helpers
const Auth = {
    // Check if user is authenticated
    isAuthenticated: function() {
        return document.body.dataset.authenticated === 'true';
    },

    // Redirect to login
    redirectToLogin: function() {
        window.location.href = '/auth/login';
    }
};

// Form validation
const FormValidator = {
    // Validate entire form
    validateForm: function(formElement) {
        const fields = formElement.querySelectorAll('[required]');
        let isValid = true;
        const errors = [];

        fields.forEach(field => {
            const fieldValid = this.validateField(field);
            if (!fieldValid.isValid) {
                isValid = false;
                errors.push({
                    field: field.name,
                    message: fieldValid.message
                });
            }
        });

        return { isValid, errors };
    },

    // Validate individual field
    validateField: function(field) {
        const value = field.value.trim();
        const type = field.type;
        const required = field.hasAttribute('required');
        
        // Check if required field is empty
        if (required && !value) {
            this.setFieldError(field, 'This field is required');
            return { isValid: false, message: 'This field is required' };
        }

        // Type-specific validation
        if (value) {
            switch (type) {
                case 'email':
                    if (!this.isValidEmail(value)) {
                        this.setFieldError(field, 'Please enter a valid email address');
                        return { isValid: false, message: 'Invalid email format' };
                    }
                    break;
                case 'date':
                    if (!this.isValidDate(value)) {
                        this.setFieldError(field, 'Please enter a valid date');
                        return { isValid: false, message: 'Invalid date format' };
                    }
                    break;
            }

            // Length validation
            const maxLength = field.getAttribute('maxlength');
            if (maxLength && value.length > parseInt(maxLength)) {
                this.setFieldError(field, `Maximum ${maxLength} characters allowed`);
                return { isValid: false, message: `Exceeds maximum length of ${maxLength}` };
            }
        }

        this.setFieldValid(field);
        return { isValid: true };
    },

    // Email validation
    isValidEmail: function(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    },

    // Date validation
    isValidDate: function(dateString) {
        const date = new Date(dateString);
        return date instanceof Date && !isNaN(date);
    },

    // Set field error state
    setFieldError: function(field, message) {
        field.classList.add('is-invalid');
        field.classList.remove('is-valid');
        
        const feedback = field.parentNode.querySelector('.invalid-feedback');
        if (feedback) {
            feedback.textContent = message;
        }
    },

    // Set field valid state
    setFieldValid: function(field) {
        field.classList.add('is-valid');
        field.classList.remove('is-invalid');
    },

    // Clear field validation state
    clearFieldValidation: function(field) {
        field.classList.remove('is-valid', 'is-invalid');
    }
};

// Progress tracking
const ProgressTracker = {
    // Update form progress
    updateProgress: function() {
        const form = document.getElementById('helpdeskForm');
        if (!form) return;

        const fields = form.querySelectorAll('input, select, textarea');
        const requiredFields = form.querySelectorAll('[required]');
        
        let totalFields = 0;
        let completedFields = 0;
        let requiredCompleted = 0;

        // Count all visible fields
        fields.forEach(field => {
            const fieldContainer = field.closest('.field-container');
            if (fieldContainer && !fieldContainer.classList.contains('d-none')) {
                totalFields++;
                if (this.isFieldCompleted(field)) {
                    completedFields++;
                }
            }
        });

        // Count required fields
        requiredFields.forEach(field => {
            const fieldContainer = field.closest('.field-container');
            if (fieldContainer && !fieldContainer.classList.contains('d-none')) {
                if (this.isFieldCompleted(field)) {
                    requiredCompleted++;
                }
            }
        });

        // Update progress bar
        const progressBar = document.getElementById('progressBar');
        const progressPercentage = totalFields > 0 ? (completedFields / totalFields) * 100 : 0;
        
        if (progressBar) {
            progressBar.style.width = progressPercentage + '%';
        }

        // Update counters
        const completedCounter = document.getElementById('completedFields');
        const totalCounter = document.getElementById('totalFields');
        
        if (completedCounter) completedCounter.textContent = completedFields;
        if (totalCounter) totalCounter.textContent = totalFields;

        // Update field progress list
        this.updateFieldProgressList();

        return {
            total: totalFields,
            completed: completedFields,
            percentage: progressPercentage,
            requiredCompleted: requiredCompleted
        };
    },

    // Check if field is completed
    isFieldCompleted: function(field) {
        if (field.type === 'checkbox') {
            return field.checked;
        } else if (field.type === 'file') {
            return field.files.length > 0;
        } else {
            return field.value.trim() !== '';
        }
    },

    // Update field progress list in sidebar
    updateFieldProgressList: function() {
        const progressContainer = document.getElementById('fieldProgress');
        if (!progressContainer) return;

        const form = document.getElementById('helpdeskForm');
        const fieldContainers = form.querySelectorAll('.field-container:not(.d-none)');
        
        let progressHTML = '';
        
        fieldContainers.forEach(container => {
            const field = container.querySelector('input, select, textarea');
            const label = container.querySelector('label');
            const isRequired = field && field.hasAttribute('required');
            const isCompleted = field && this.isFieldCompleted(field);
            
            if (field && label) {
                const iconClass = isCompleted ? 'bi-check-circle-fill' : 
                                isRequired ? 'bi-exclamation-circle' : 'bi-circle';
                const statusClass = isCompleted ? 'completed' : 
                                  isRequired ? 'required incomplete' : 'incomplete';
                
                progressHTML += `
                    <div class="field-progress-item ${statusClass}">
                        <i class="bi ${iconClass} field-progress-icon"></i>
                        <span>${label.textContent.replace('*', '').trim()}</span>
                    </div>
                `;
            }
        });
        
        progressContainer.innerHTML = progressHTML;
    }
};

// Initialize application
document.addEventListener('DOMContentLoaded', function() {
    console.log('Support Portal - Application initialized');
    
    // Initialize Bootstrap tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize form validation for login and other forms
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const alertInstance = new bootstrap.Alert(alert);
            alertInstance.close();
        }, 5000);
    });

    // Initialize logout form handling
    const logoutForms = document.querySelectorAll('form[action="/auth/logout"]');
    logoutForms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            
            if (confirm('Are you sure you want to logout?')) {
                Utils.showLoading();
                form.submit();
            }
        });
    });
});

// Export for use in other scripts
window.HelpDesk = {
    Utils,
    Auth,
    FormValidator,
    ProgressTracker
};
