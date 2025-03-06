# Payment Plugin Setup Guide

## Configuration Steps

In the specified path:  
`\app\config\config.php`  

Search for the following variable:  
`$config['csrf_protection']`  

Add the following code snippet after it:  
```php
if (stripos($_SERVER["REQUEST_URI"],'/add_funds/tabapay') !== FALSE ) { 
    $config['csrf_protection'] = FALSE; 
}
```

Additionally, this has been added to the config-sample.php file in the downloaded plugin, which you can review.

If desired, you can rename it to config.php and replace it in your script's path.

## Database Setup
```sql
INSERT INTO `payments` (`type`, `name`, `min`, `max`, `new_users`, `status`, `params`) VALUES 
('tabapay', 'TabaPay Gateway', 100, 1000000, 1, 1, '{"type":"tabapay","name":"TabaPay Gateway","min":"100","max":"1000000","new_users":"1","status":"1","option":{"tnx_fee":"0","environment":"live","MerchantID":"1234"}}');
```
