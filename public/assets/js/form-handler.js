// Form Handler for Dynamic Support Portal
'use strict';

// Form handler object
const FormHandler = {
    config: null,
    autosaveTimer: null,
    isSubmitting: false, // Track submission state to prevent duplicates
    
    // Initialize form functionality
    init: function(config) {
        this.config = config;
        console.log('FormHandler initialized with config:', config);
        
        this.setupFormEvents();
        this.setupFileUploads();
        this.setupConditionalFields();
        this.setupAutosave();
        this.setupFormValidation();
        this.loadAutosavedData();
        this.updateProgress();
    },

    // Setup form event listeners
    setupFormEvents: function() {
        const form = document.getElementById('helpdeskForm');
        if (!form) return;

        // Form submission
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleFormSubmission();
        });

        // Clear form button
        const clearBtn = document.getElementById('clearForm');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                this.clearForm();
            });
        }

        // Validate form button
        const validateBtn = document.getElementById('validateForm');
        if (validateBtn) {
            validateBtn.addEventListener('click', () => {
                this.validateForm();
            });
        }

        // Field change events for progress tracking
        const fields = form.querySelectorAll('input, select, textarea');
        fields.forEach(field => {
            field.addEventListener('change', () => {
                HelpDesk.ProgressTracker.updateProgress();
                this.debouncedAutosave();
            });

            field.addEventListener('input', () => {
                this.debouncedAutosave();
            });
        });
    },

    // Setup file upload functionality
    setupFileUploads: function() {
        const fileFields = document.querySelectorAll('input[type="file"]');
        
        fileFields.forEach(field => {
            const fieldName = field.name.replace('[]', '');
            const dropZone = document.getElementById(`dropZone_${fieldName}`);
            const fileList = document.getElementById(`fileList_${fieldName}`);
            
            if (!dropZone || !fileList) return;

            // Click to select files
            dropZone.addEventListener('click', (e) => {
                // Prevent triggering if clicking on the file input itself
                if (e.target === field) {
                    return;
                }
                // Prevent event bubbling to avoid double-trigger
                e.preventDefault();
                e.stopPropagation();
                field.click();
            });

            // Drag and drop events
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });

            dropZone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                
                const files = Array.from(e.dataTransfer.files);
                this.handleFileSelection(field, files, fileList);
            });

            // File input change
            field.addEventListener('change', (e) => {
                const files = Array.from(e.target.files);
                this.handleFileSelection(field, files, fileList);
            });
        });
    },

    // Handle file selection
    handleFileSelection: function(field, files, fileList) {
        const fieldName = field.name.replace('[]', '');
        const isMultiple = field.hasAttribute('multiple');
        const accept = field.getAttribute('accept');
        
        // Filter valid files
        const validFiles = files.filter(file => {
            if (accept && accept !== '*') {
                const acceptedTypes = accept.split(',').map(type => type.trim());
                const fileType = file.type;
                const fileName = file.name.toLowerCase();
                
                return acceptedTypes.some(type => {
                    if (type.startsWith('.')) {
                        return fileName.endsWith(type);
                    } else if (type.includes('/')) {
                        return fileType === type || fileType.startsWith(type.replace('*', ''));
                    }
                    return false;
                });
            }
            return true;
        });

        if (validFiles.length !== files.length) {
            HelpDesk.Utils.showToast(
                'Some files were rejected due to invalid file type.',
                'warning'
            );
        }

        // Check file size limits
        const maxSize = this.parseFileSize(this.config.settings.max_file_size || '10MB');
        const oversizedFiles = validFiles.filter(file => file.size > maxSize);
        
        if (oversizedFiles.length > 0) {
            HelpDesk.Utils.showToast(
                `Some files exceed the maximum size limit of ${this.config.settings.max_file_size}.`,
                'error'
            );
            return;
        }

        // Update file list display
        this.updateFileList(fieldName, validFiles, fileList, isMultiple);
        
        // Update form progress
        HelpDesk.ProgressTracker.updateProgress();
    },

    // Update file list display
    updateFileList: function(fieldName, files, fileList, isMultiple) {
        let existingFiles = [];
        
        if (isMultiple) {
            // Get existing files from display
            const existingItems = fileList.querySelectorAll('.file-item');
            existingFiles = Array.from(existingItems).map(item => ({
                name: item.dataset.fileName,
                size: parseInt(item.dataset.fileSize),
                file: item.fileObject
            }));
        }

        // Combine existing and new files
        const allFiles = isMultiple ? [...existingFiles, ...files] : files;
        
        // Clear file list
        fileList.innerHTML = '';
        
        // Add file items
        allFiles.forEach((fileData, index) => {
            const file = fileData.file || fileData;
            const fileItem = this.createFileItem(file, index, fieldName);
            fileList.appendChild(fileItem);
        });

        // Show file list if there are files
        fileList.style.display = allFiles.length > 0 ? 'block' : 'none';
    },

    // Create file item element
    createFileItem: function(file, index, fieldName) {
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        fileItem.dataset.fileName = file.name;
        fileItem.dataset.fileSize = file.size;
        fileItem.fileObject = file;

        const fileIcon = this.getFileIcon(file.name);
        
        fileItem.innerHTML = `
            <div class="file-info">
                <i class="bi ${fileIcon} file-icon"></i>
                <div class="file-details">
                    <div class="file-name">${file.name}</div>
                    <div class="file-size">${HelpDesk.Utils.formatFileSize(file.size)}</div>
                </div>
            </div>
            <button type="button" class="file-remove" title="Remove file">
                <i class="bi bi-x-lg"></i>
            </button>
        `;

        // Add remove functionality
        const removeBtn = fileItem.querySelector('.file-remove');
        removeBtn.addEventListener('click', () => {
            fileItem.remove();
            this.updateFileInputFromList(fieldName);
            HelpDesk.ProgressTracker.updateProgress();
        });

        return fileItem;
    },

    // Get appropriate icon for file type
    getFileIcon: function(fileName) {
        const extension = fileName.split('.').pop().toLowerCase();
        const iconMap = {
            pdf: 'bi-file-earmark-pdf',
            doc: 'bi-file-earmark-word',
            docx: 'bi-file-earmark-word',
            xls: 'bi-file-earmark-excel',
            xlsx: 'bi-file-earmark-excel',
            ppt: 'bi-file-earmark-ppt',
            pptx: 'bi-file-earmark-ppt',
            txt: 'bi-file-earmark-text',
            jpg: 'bi-file-earmark-image',
            jpeg: 'bi-file-earmark-image',
            png: 'bi-file-earmark-image',
            gif: 'bi-file-earmark-image',
            zip: 'bi-file-earmark-zip',
            rar: 'bi-file-earmark-zip'
        };
        
        return iconMap[extension] || 'bi-file-earmark';
    },

    // Update file input from display list
    updateFileInputFromList: function(fieldName) {
        const fileList = document.getElementById(`fileList_${fieldName}`);
        const fileInput = document.querySelector(`input[name="${fieldName}"], input[name="${fieldName}[]"]`);
        
        if (!fileList || !fileInput) return;

        const fileItems = fileList.querySelectorAll('.file-item');
        const files = Array.from(fileItems).map(item => item.fileObject).filter(Boolean);
        
        // Create new FileList (we can't modify the original)
        const dataTransfer = new DataTransfer();
        files.forEach(file => dataTransfer.items.add(file));
        fileInput.files = dataTransfer.files;
    },

    // Parse file size string (e.g., "10MB" -> bytes)
    parseFileSize: function(sizeStr) {
        const units = { B: 1, KB: 1024, MB: 1024 * 1024, GB: 1024 * 1024 * 1024 };
        const match = sizeStr.match(/^(\d+(\.\d+)?)\s*(B|KB|MB|GB)$/i);
        
        if (match) {
            const value = parseFloat(match[1]);
            const unit = match[3].toUpperCase();
            return value * (units[unit] || 1);
        }
        
        return 10 * 1024 * 1024; // Default 10MB
    },

    // Setup conditional field logic
    setupConditionalFields: function() {
        const fieldsWithTriggers = document.querySelectorAll('[data-triggers]');
        
        fieldsWithTriggers.forEach(field => {
            try {
                const triggers = JSON.parse(field.dataset.triggers);
                
                field.addEventListener('change', () => {
                    this.handleConditionalTriggers(field, triggers);
                });
                
                // Initial check
                this.handleConditionalTriggers(field, triggers);
            } catch (e) {
                console.error('Error parsing triggers for field:', field.name, e);
            }
        });
    },

    // Handle conditional field triggers
    handleConditionalTriggers: function(triggerField, triggers) {
        const value = triggerField.value;
        
        triggers.forEach(trigger => {
            const condition = trigger.condition;
            const showFields = trigger.show_fields || [];
            
            // Evaluate condition (simple implementation)
            let showConditionalFields = false;
            
            try {
                // Replace 'value' with actual value in condition
                const evalCondition = condition.replace(/value/g, `"${value}"`);
                showConditionalFields = eval(evalCondition);
            } catch (e) {
                console.error('Error evaluating condition:', condition, e);
            }
            
            // Show/hide conditional fields
            showFields.forEach(fieldName => {
                const fieldContainer = document.querySelector(`[data-field="${fieldName}"]`);
                if (fieldContainer) {
                    if (showConditionalFields) {
                        fieldContainer.classList.remove('d-none');
                        fieldContainer.classList.add('fade-in');
                    } else {
                        fieldContainer.classList.add('d-none');
                        fieldContainer.classList.remove('fade-in');
                        
                        // Clear field values when hidden
                        const fields = fieldContainer.querySelectorAll('input, select, textarea');
                        fields.forEach(field => {
                            field.value = '';
                            HelpDesk.FormValidator.clearFieldValidation(field);
                        });
                    }
                }
            });
        });
        
        // Update progress after showing/hiding fields
        HelpDesk.ProgressTracker.updateProgress();
    },

    // Setup autosave functionality
    setupAutosave: function() {
        if (!this.config.autosaveInterval) return;

        this.debouncedAutosave = HelpDesk.Utils.debounce(() => {
            this.autosave();
        }, 1000);
    },

    // Perform autosave
    autosave: function() {
        const formData = this.getFormData();
        
        HelpDesk.Utils.apiRequest('/api/autosave', {
            method: 'POST',
            body: JSON.stringify({
                request_type: this.config.requestType,
                form_data: formData,
                csrf_token: document.querySelector('input[name="csrf_token"]').value
            })
        })
        .then(() => {
            this.showAutosaveIndicator();
        })
        .catch(error => {
            console.error('Autosave failed:', error);
        });
    },

    // Show autosave indicator
    showAutosaveIndicator: function() {
        const indicator = document.getElementById('autosaveStatus');
        if (!indicator) return;

        indicator.classList.remove('d-none');
        
        setTimeout(() => {
            indicator.classList.add('d-none');
        }, 2000);
    },

    // Load autosaved data
    loadAutosavedData: function() {
        if (!this.config.autosavedData) return;

        const data = this.config.autosavedData;
        
        Object.keys(data).forEach(fieldName => {
            const field = document.querySelector(`[name="${fieldName}"]`);
            if (field && data[fieldName]) {
                if (field.type === 'checkbox') {
                    field.checked = data[fieldName];
                } else if (field.type === 'radio') {
                    if (field.value === data[fieldName]) {
                        field.checked = true;
                    }
                } else {
                    field.value = data[fieldName];
                }
            }
        });
        
        HelpDesk.ProgressTracker.updateProgress();
    },

    // Setup form validation
    setupFormValidation: function() {
        const form = document.getElementById('helpdeskForm');
        if (!form) return;

        // Real-time validation
        const fields = form.querySelectorAll('input, select, textarea');
        fields.forEach(field => {
            field.addEventListener('blur', () => {
                HelpDesk.FormValidator.validateField(field);
            });
        });
    },

    // Get form data
    getFormData: function() {
        const form = document.getElementById('helpdeskForm');
        const formData = new FormData(form);
        const data = {};

        for (let [key, value] of formData.entries()) {
            if (data[key]) {
                // Handle multiple values (arrays)
                if (Array.isArray(data[key])) {
                    data[key].push(value);
                } else {
                    data[key] = [data[key], value];
                }
            } else {
                data[key] = value;
            }
        }

        return data;
    },

    // Validate entire form
    validateForm: function() {
        const form = document.getElementById('helpdeskForm');
        const validation = HelpDesk.FormValidator.validateForm(form);
        
        if (validation.isValid) {
            HelpDesk.Utils.showToast('Form validation passed!', 'success');
        } else {
            HelpDesk.Utils.showToast(`Found ${validation.errors.length} validation error(s)`, 'error');
            
            // Focus first invalid field
            if (validation.errors.length > 0) {
                const firstErrorField = form.querySelector(`[name="${validation.errors[0].field}"]`);
                if (firstErrorField) {
                    firstErrorField.focus();
                    firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }
        
        return validation.isValid;
    },

    // Handle form submission
    handleFormSubmission: function() {
        // Prevent duplicate submissions
        if (this.isSubmitting) {
            console.log('Form submission already in progress');
            return;
        }
        
        if (!this.validateForm()) {
            return;
        }

        // Show confirmation modal
        this.showSubmissionModal();
    },

    // Show submission confirmation modal
    showSubmissionModal: function() {
        const modal = document.getElementById('submitConfirmModal');
        if (!modal) {
            this.submitForm();
            return;
        }

        // Populate summary
        const summaryContainer = document.getElementById('submissionSummary');
        if (summaryContainer) {
            summaryContainer.innerHTML = this.generateSubmissionSummary();
        }

        // Setup confirm button
        const confirmBtn = document.getElementById('confirmSubmit');
        if (confirmBtn) {
            confirmBtn.onclick = () => {
                const modalInstance = bootstrap.Modal.getInstance(modal);
                modalInstance.hide();
                this.submitForm();
            };
        }

        // Show modal
        const modalInstance = new bootstrap.Modal(modal);
        modalInstance.show();
    },

    // Generate submission summary
    generateSubmissionSummary: function() {
        const formData = this.getFormData();
        let summary = '<div class="small">';
        
        Object.keys(formData).forEach(key => {
            if (key === 'csrf_token' || key === 'request_type') return;
            
            const field = document.querySelector(`[name="${key}"]`);
            const label = field ? 
                (field.closest('.field-container')?.querySelector('label')?.textContent.replace('*', '').trim() || key) :
                key;
            
            let value = formData[key];
            
            // Handle File objects
            if (value instanceof File) {
                const fileSize = (value.size / 1024).toFixed(2);
                value = `📎 ${value.name} (${fileSize} KB)`;
            } 
            // Handle arrays of values (including Files)
            else if (Array.isArray(value)) {
                value = value.map(item => {
                    if (item instanceof File) {
                        const fileSize = (item.size / 1024).toFixed(2);
                        return `📎 ${item.name} (${fileSize} KB)`;
                    }
                    return item;
                }).join(', ');
            }
            
            if (value && value !== '') {
                summary += `<strong>${label}:</strong> ${value}<br>`;
            }
        });
        
        summary += '</div>';
        return summary;
    },

    // Submit form
    submitForm: function() {
        // Prevent duplicate submissions
        if (this.isSubmitting) {
            console.log('Form submission already in progress');
            return;
        }
        
        this.isSubmitting = true;
        
        const form = document.getElementById('helpdeskForm');
        
        HelpDesk.Utils.showLoading();
        
        // Add form submission tracking
        const submitBtn = document.getElementById('submitForm');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Submitting...';
        }

        // Submit form via AJAX to prevent duplicate submissions on refresh
        const formData = new FormData(form);
        
        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => {
                    throw new Error(data.error || 'Submission failed');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.redirect_url) {
                // Redirect to success page (PRG pattern)
                window.location.href = data.redirect_url;
            } else {
                throw new Error('Invalid response from server');
            }
        })
        .catch(error => {
            console.error('Form submission error:', error);
            HelpDesk.Utils.hideLoading();
            
            // Reset submission flag
            this.isSubmitting = false;
            
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send me-2"></i>Submit Request';
            }
            
            HelpDesk.Utils.showToast(
                error.message || 'An error occurred while submitting the form. Please try again.',
                'error'
            );
        });
    },

    // Clear form
    clearForm: function() {
        if (!confirm('Are you sure you want to clear the form? All entered data will be lost.')) {
            return;
        }

        const form = document.getElementById('helpdeskForm');
        
        // Clear all fields
        const fields = form.querySelectorAll('input, select, textarea');
        fields.forEach(field => {
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = false;
            } else if (field.type === 'file') {
                field.value = '';
            } else {
                field.value = '';
            }
            
            HelpDesk.FormValidator.clearFieldValidation(field);
        });

        // Clear file lists
        const fileLists = document.querySelectorAll('.file-list');
        fileLists.forEach(list => {
            list.innerHTML = '';
            list.style.display = 'none';
        });

        // Hide conditional fields
        const conditionalFields = document.querySelectorAll('.conditional-field');
        conditionalFields.forEach(field => {
            field.classList.add('d-none');
        });

        // Clear autosaved data
        this.autosave();
        
        // Update progress
        HelpDesk.ProgressTracker.updateProgress();
        
        HelpDesk.Utils.showToast('Form cleared successfully', 'info');
    },

    // Update progress
    updateProgress: function() {
        HelpDesk.ProgressTracker.updateProgress();
    }
};

// Initialize form when DOM is ready and config is available
function initializeForm() {
    if (typeof formConfig !== 'undefined') {
        FormHandler.init(formConfig);
    } else {
        console.error('Form configuration not found');
    }
}

function setupAutosave() {
    FormHandler.setupAutosave();
}

function setupFileUploads() {
    FormHandler.setupFileUploads();
}

function setupFormValidation() {
    FormHandler.setupFormValidation();
}

function setupConditionalFields() {
    FormHandler.setupConditionalFields();
}

function loadAutosavedData() {
    FormHandler.loadAutosavedData();
}

// Export for global use
window.FormHandler = FormHandler;
