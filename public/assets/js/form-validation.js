/**
 * Form Validation Module
 * Provides real-time inline form validation with error messages
 */

const FormValidator = {
  /**
   * Validation rules for form fields
   */
  rules: {
    first_name: {
      required: true,
      maxLength: 50,
      pattern: /^[\s\w'-]*$/,
      message: {
        required: 'First name is required.',
        maxLength: 'First name must be 50 characters or fewer.',
        pattern: 'First name contains invalid characters.'
      }
    },
    last_name: {
      required: true,
      maxLength: 50,
      pattern: /^[\s\w'-]*$/,
      message: {
        required: 'Last name is required.',
        maxLength: 'Last name must be 50 characters or fewer.',
        pattern: 'Last name contains invalid characters.'
      }
    },
    email: {
      required: true,
      email: true,
      maxLength: 100,
      message: {
        required: 'Email address is required.',
        email: 'Please enter a valid email address.',
        maxLength: 'Email must be 100 characters or fewer.'
      }
    },
    password: {
      required: true,
      minLength: 8,
      message: {
        required: 'Password is required.',
        minLength: 'Password must be at least 8 characters.'
      }
    },
    new_password: {
      minLength: 8,
      message: {
        minLength: 'Password must be at least 8 characters.',
        required: 'Password is required.'
      }
    },
    role: {
      required: true,
      message: {
        required: 'Please select a role.'
      }
    },
    student_batch: {
      maxLength: 80,
      message: {
        maxLength: 'Batch / cohort must be 80 characters or fewer.'
      }
    }
  },

  /**
   * Validate a single field
   */
  validateField(fieldName, value, fieldRules = null) {
    const rules = fieldRules || this.rules[fieldName];
    if (!rules) return null;

    const trimmedValue = typeof value === 'string' ? value.trim() : value;

    // Check required
    if (rules.required && !trimmedValue) {
      return rules.message.required;
    }

    // If not required and empty, pass validation
    if (!rules.required && !trimmedValue) {
      return null;
    }

    // Check email format
    if (rules.email) {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(trimmedValue)) {
        return rules.message.email;
      }
    }

    // Check max length
    if (rules.maxLength && trimmedValue.length > rules.maxLength) {
      return rules.message.maxLength;
    }

    // Check min length
    if (rules.minLength && trimmedValue.length < rules.minLength) {
      return rules.message.minLength;
    }

    // Check pattern
    if (rules.pattern && !rules.pattern.test(trimmedValue)) {
      return rules.message.pattern;
    }

    return null;
  },

  /**
   * Validate entire form
   */
  validateForm(form) {
    const fields = form.querySelectorAll('input[name], select[name], textarea[name]');
    const errors = {};
    let hasErrors = false;

    fields.forEach(field => {
      const fieldName = field.name;
      const fieldValue = field.type === 'checkbox' || field.type === 'radio' ? field.checked : field.value;

      const error = this.validateField(fieldName, fieldValue);
      if (error) {
        errors[fieldName] = error;
        hasErrors = true;
        this.showFieldError(field, error);
      } else {
        this.clearFieldError(field);
      }
    });

    return { isValid: !hasErrors, errors };
  },

  /**
   * Display error message below field
   */
  showFieldError(field, message) {
    // Remove existing error message
    const existingError = field.parentElement.querySelector('.invalid-feedback');
    if (existingError) {
      existingError.remove();
    }

    // Add error class
    field.classList.add('is-invalid');

    // Create and append error message
    const errorDiv = document.createElement('div');
    errorDiv.className = 'invalid-feedback d-block';
    errorDiv.textContent = message;
    field.parentElement.appendChild(errorDiv);
  },

  /**
   * Clear error message from field
   */
  clearFieldError(field) {
    field.classList.remove('is-invalid');
    const existingError = field.parentElement.querySelector('.invalid-feedback');
    if (existingError) {
      existingError.remove();
    }
  },

  /**
   * Setup real-time validation for a form
   */
  setupFormValidation(formId) {
    const form = document.getElementById(formId);
    if (!form) return;

    const fields = form.querySelectorAll('input[name], select[name], textarea[name]');

    // Real-time validation on input
    fields.forEach(field => {
      field.addEventListener('blur', (e) => {
        const error = this.validateField(field.name, field.value);
        if (error) {
          this.showFieldError(field, error);
        } else {
          this.clearFieldError(field);
        }
      });

      // Clear error on focus
      field.addEventListener('focus', (e) => {
        if (field.classList.contains('is-invalid')) {
          this.clearFieldError(field);
        }
      });
    });

    // Prevent form submission if there are errors
    form.addEventListener('submit', (e) => {
      const validation = this.validateForm(form);
      if (!validation.isValid) {
        e.preventDefault();
        // Focus on first invalid field
        const firstInvalidField = form.querySelector('.is-invalid');
        if (firstInvalidField) {
          firstInvalidField.focus();
          firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }
    });
  },

  /**
   * Setup validation for all forms with data-validate attribute
   */
  initializeAll() {
    const forms = document.querySelectorAll('form[data-validate="true"]');
    forms.forEach(form => {
      const formId = form.id || form.getAttribute('data-form-id');
      if (formId) {
        this.setupFormValidation(formId);
      }
    });
  }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  FormValidator.initializeAll();
});
