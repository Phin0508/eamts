<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Registration System</title>
    <link rel="stylesheet" href="../style/assetreg.css">
    <style>
        
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-icon">📦</div>
            <div>
                <h1>Register New Asset</h1>
                <p>Add a new asset to the inventory management system</p>
            </div>
        </div>

        <!-- Alert Messages -->
        <div class="alert success" id="successAlert">
            <span>✓</span>
            <span>Asset registered successfully!</span>
        </div>

        <div class="alert error" id="errorAlert">
            <span>⚠</span>
            <span>Please fix the errors before submitting</span>
        </div>

        <!-- Form -->
        <div class="form-container">
            <form id="assetForm" action="register_asset.php" method="POST">
                <!-- Basic Information -->
                <h2 class="section-title">Basic Information</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Asset Name <span class="required">*</span></label>
                        <input type="text" name="asset_name" id="asset_name" placeholder="e.g., Dell Laptop" required>
                        <span class="error-message">Asset name is required</span>
                    </div>

                    <div class="form-group">
                        <label>Asset Code <span class="required">*</span></label>
                        <input type="text" name="asset_code" id="asset_code" placeholder="e.g., ASSET-001" required>
                        <span class="error-message">Asset code is required</span>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="category">
                            <option value="">Select Category</option>
                            <option value="Computer Equipment">Computer Equipment</option>
                            <option value="Furniture">Furniture</option>
                            <option value="Vehicles">Vehicles</option>
                            <option value="Machinery">Machinery</option>
                            <option value="Office Equipment">Office Equipment</option>
                            <option value="Electronics">Electronics</option>
                            <option value="Tools">Tools</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status">
                            <option value="available">Available</option>
                            <option value="in_use">In Use</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="retired">Retired</option>
                        </select>
                    </div>
                </div>

                <!-- Product Details -->
                <h2 class="section-title">Product Details</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Brand</label>
                        <input type="text" name="brand" id="brand" placeholder="e.g., Dell">
                    </div>

                    <div class="form-group">
                        <label>Model</label>
                        <input type="text" name="model" id="model" placeholder="e.g., Latitude 5420">
                    </div>

                    <div class="form-group">
                        <label>Serial Number</label>
                        <input type="text" name="serial_number" id="serial_number" placeholder="e.g., SN123456789">
                    </div>

                    <div class="form-group">
                        <label>Supplier</label>
                        <input type="text" name="supplier" id="supplier" placeholder="e.g., Tech Solutions Inc.">
                    </div>
                </div>

                <!-- Purchase Information -->
                <h2 class="section-title">Purchase Information</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Purchase Date</label>
                        <input type="date" name="purchase_date" id="purchase_date">
                    </div>

                    <div class="form-group">
                        <label>Purchase Cost</label>
                        <input type="number" name="purchase_cost" id="purchase_cost" step="0.01" min="0" placeholder="0.00">
                        <span class="error-message">Please enter a valid amount</span>
                    </div>

                    <div class="form-group">
                        <label>Warranty Expiry</label>
                        <input type="date" name="warranty_expiry" id="warranty_expiry">
                    </div>
                </div>

                <!-- Location & Assignment -->
                <h2 class="section-title">Location & Assignment</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" name="location" id="location" placeholder="e.g., Building A, Floor 3, Room 301">
                    </div>

                    <div class="form-group">
                        <label>Department</label>
                        <select name="department" id="department">
                            <option value="">Select Department</option>
                            <option value="IT">IT</option>
                            <option value="Finance">Finance</option>
                            <option value="HR">HR</option>
                            <option value="Operations">Operations</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Sales">Sales</option>
                            <option value="Administration">Administration</option>
                            <option value="Manufacturing">Manufacturing</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Assigned To (User ID)</label>
                        <input type="number" name="assigned_to" id="assigned_to" placeholder="Enter user ID">
                    </div>

                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea name="description" id="description" placeholder="Additional notes or description about the asset..."></textarea>
                    </div>
                </div>

                <!-- Hidden field for created_by - in real implementation, this would come from session -->
                <input type="hidden" name="created_by" value="1">

                <!-- Buttons -->
                <div class="button-group">
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">Reset</button>
                    <button type="submit" class="btn btn-primary">Register Asset</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const form = document.getElementById('assetForm');
        const successAlert = document.getElementById('successAlert');
        const errorAlert = document.getElementById('errorAlert');

        // Form validation
        function validateForm() {
            let isValid = true;
            const assetName = document.getElementById('asset_name');
            const assetCode = document.getElementById('asset_code');
            const purchaseCost = document.getElementById('purchase_cost');

            // Remove all error classes first
            document.querySelectorAll('.form-group').forEach(group => {
                group.classList.remove('error');
            });

            // Validate asset name
            if (!assetName.value.trim()) {
                assetName.closest('.form-group').classList.add('error');
                isValid = false;
            }

            // Validate asset code
            if (!assetCode.value.trim()) {
                assetCode.closest('.form-group').classList.add('error');
                isValid = false;
            }

            // Validate purchase cost
            if (purchaseCost.value && (isNaN(purchaseCost.value) || parseFloat(purchaseCost.value) < 0)) {
                purchaseCost.closest('.form-group').classList.add('error');
                isValid = false;
            }

            return isValid;
        }

        // Handle form submission
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (validateForm()) {
                // Hide error alert
                errorAlert.classList.remove('show');

                // Get form data
                const formData = new FormData(form);
                
                // Disable submit button
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.textContent = 'Registering...';
                
                // Submit to PHP backend
                fetch('register_asset.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Re-enable button
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                    
                    if (data.success) {
                        showSuccess();
                        console.log('Asset registered with ID:', data.asset_id);
                    } else {
                        // Show error message
                        errorAlert.querySelector('span:last-child').textContent = 
                            data.message || 'Failed to register asset';
                        errorAlert.classList.add('show');
                        
                        // Log errors to console
                        if (data.errors && data.errors.length > 0) {
                            console.error('Validation errors:', data.errors);
                        }
                    }
                })
                .catch(error => {
                    // Re-enable button
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                    
                    console.error('Error:', error);
                    errorAlert.querySelector('span:last-child').textContent = 
                        'An error occurred. Please try again.';
                    errorAlert.classList.add('show');
                });
            } else {
                errorAlert.classList.add('show');
                // Scroll to first error
                const firstError = document.querySelector('.form-group.error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });

        function showSuccess() {
            successAlert.classList.add('show');
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            setTimeout(() => {
                form.reset();
                successAlert.classList.remove('show');
            }, 3000);
        }

        function resetForm() {
            form.reset();
            document.querySelectorAll('.form-group').forEach(group => {
                group.classList.remove('error');
            });
            successAlert.classList.remove('show');
            errorAlert.classList.remove('show');
        }

        // Real-time validation
        document.querySelectorAll('input[required], select[required]').forEach(field => {
            field.addEventListener('blur', function() {
                if (!this.value.trim()) {
                    this.closest('.form-group').classList.add('error');
                } else {
                    this.closest('.form-group').classList.remove('error');
                }
            });

            field.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.closest('.form-group').classList.remove('error');
                }
            });
        });
    </script>
</body>
</html>