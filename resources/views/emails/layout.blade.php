<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>@yield('title', 'CediBites')</title>
    <style>
        /* Reset styles */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        
        /* Base styles */
        body {
            margin: 0;
            padding: 0;
            width: 100% !important;
            font-family: 'Cabin', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background-color: #1d1a16;
            color: #fbf6ed;
            line-height: 1.6;
        }
        
        /* Container */
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #1d1a16;
        }
        
        /* Header */
        .email-header {
            background-color: #1d1a16;
            padding: 20px 20px 10px 20px;
            text-align: left;
        }
        
        .logo {
            width: 40px;
            height: 40px;
            vertical-align: middle;
            display: inline-block;
            margin-right: 10px;
        }
        
        .brand-name {
            color: #e49925;
            font-size: 24px;
            font-weight: bold;
            margin: 0;
            font-family: 'Cabin', sans-serif;
            display: inline-block;
            vertical-align: middle;
            line-height: 40px;
        }
        
        /* Content */
        .email-content {
            padding: 20px;
            background-color: #1d1a16;
        }
        
        .greeting {
            font-size: 20px;
            font-weight: 600;
            color: #fbf6ed;
            margin: 0 0 12px 0;
            font-family: 'Cabin', sans-serif;
        }
        
        .message {
            font-size: 15px;
            color: #fbf6ed;
            margin: 0 0 12px 0;
            font-family: 'Cabin', sans-serif;
        }
        
        /* Button */
        .button {
            display: inline-block;
            padding: 12px 28px;
            background-color: #e49925;
            color: #1d1a16 !important;
            text-decoration: none;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 15px;
            margin: 12px 0;
            font-family: 'Cabin', sans-serif;
        }
        
        .button:hover {
            background-color: #f1ab3e;
        }
        
        /* Order details box */
        .order-box {
            background-color: #2a2621;
            padding: 15px;
            margin: 12px 0;
            border-radius: 12px;
        }
        
        .order-box h3 {
            margin: 0 0 10px 0;
            color: #fbf6ed;
            font-size: 16px;
            font-family: 'Cabin', sans-serif;
        }
        
        .order-detail {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #3a3530;
        }
        
        .order-detail:last-child {
            border-bottom: none;
        }
        
        .order-label {
            color: #8b7f70;
            font-weight: 500;
            font-family: 'Cabin', sans-serif;
        }
        
        .order-value {
            color: #fbf6ed;
            font-weight: 600;
            font-family: 'Cabin', sans-serif;
        }
        
        /* Footer */
        .email-footer {
            background-color: #120f0d;
            padding: 20px;
            text-align: center;
            color: #8b7f70;
        }
        
        .social-links {
            margin: 0 0 12px 0;
            padding: 0;
        }
        
        .social-link {
            display: inline-block;
            margin: 0 6px;
            width: 32px;
            height: 32px;
            background-color: #e49925;
            border-radius: 50%;
            text-decoration: none;
            line-height: 32px;
            vertical-align: middle;
        }
        
        .social-link:hover {
            background-color: #f1ab3e;
        }
        
        .social-icon {
            width: 18px;
            height: 18px;
            vertical-align: middle;
            filter: brightness(0) invert(1);
        }
        
        .footer-text {
            font-size: 13px;
            color: #8b7f70;
            margin: 0 0 12px 0;
            padding: 0;
            font-family: 'Cabin', sans-serif;
            line-height: 1.6;
        }
        
        .footer-links {
            margin: 0 0 12px 0;
            padding: 0;
        }
        
        .footer-link {
            color: #e49925;
            text-decoration: none;
            margin: 0 6px;
            font-size: 13px;
            font-family: 'Cabin', sans-serif;
            display: inline-block;
        }
        
        .footer-link:hover {
            color: #f1ab3e;
        }
        
        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
            }
            
            .email-header {
                padding: 15px 15px 8px 15px !important;
            }
            
            .logo {
                width: 32px !important;
                height: 32px !important;
                margin-right: 8px !important;
            }
            
            .brand-name {
                font-size: 18px !important;
                line-height: 32px !important;
            }
            
            .email-content {
                padding: 15px !important;
            }
            
            .greeting {
                font-size: 17px !important;
                margin: 0 0 12px 0 !important;
            }
            
            .message {
                font-size: 14px !important;
                margin: 0 0 12px 0 !important;
            }
            
            .order-box {
                padding: 12px !important;
                margin: 12px 0 !important;
            }
            
            .order-box h3 {
                font-size: 15px !important;
                margin: 0 0 10px 0 !important;
            }
            
            .order-detail {
                padding: 6px 0 !important;
                display: block !important;
            }
            
            .order-label,
            .order-value {
                display: block !important;
                margin: 2px 0 !important;
            }
            
            .button {
                padding: 12px 24px !important;
                font-size: 14px !important;
                margin: 12px 0 !important;
                display: inline-block !important;
                width: auto !important;
                text-align: center !important;
            }
            
            .email-footer {
                padding: 15px !important;
            }
            
            .social-links {
                margin: 0 0 12px 0 !important;
            }
            
            .social-link {
                width: 28px !important;
                height: 28px !important;
                margin: 0 4px !important;
                line-height: 28px !important;
            }
            
            .social-icon {
                width: 16px !important;
                height: 16px !important;
            }
            
            .footer-text {
                font-size: 12px !important;
                margin: 0 0 12px 0 !important;
                line-height: 1.6 !important;
            }
            
            .footer-links {
                margin: 0 0 12px 0 !important;
            }
            
            .footer-link {
                font-size: 12px !important;
                margin: 0 4px !important;
                display: inline-block !important;
            }
        }
    </style>
</head>
<body>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #1d1a16;">
        <tr>
            <td style="padding: 10px 0;">
                <table role="presentation" class="email-container" cellspacing="0" cellpadding="0" border="0" width="600" align="center" style="width: 100%; max-width: 600px;">
                    
                    <!-- Header -->
                    <tr>
                        <td class="email-header">
                            <h1 class="brand-name">CediBites</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td class="email-content">
                            @yield('content')
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td class="email-footer">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="text-align: center; padding: 0;">
                                        <div class="social-links">
                                            <a href="https://instagram.com/cedibites" class="social-link" title="Instagram">
                                                <svg class="social-icon" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                                                </svg>
                                            </a>
                                            <a href="https://facebook.com/cedibites" class="social-link" title="Facebook">
                                                <svg class="social-icon" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                                </svg>
                                            </a>
                                            <a href="https://wa.me/233XXXXXXXXX" class="social-link" title="WhatsApp">
                                                <svg class="social-icon" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                                                </svg>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align: center; padding: 0;">
                                        <p class="footer-text">
                                            &copy; {{ date('Y') }} CediBites. All rights reserved.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align: center; padding: 0;">
                                        <div class="footer-links">
                                            <a href="{{ config('app.frontend_url') }}/privacy" class="footer-link">Privacy Policy</a>
                                            <a href="{{ config('app.frontend_url') }}/terms" class="footer-link">Terms of Service</a>
                                            <a href="{{ config('app.frontend_url') }}/contact" class="footer-link">Contact Us</a>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align: center; padding: 0;">
                                        <p class="footer-text" style="font-size: 12px; margin: 0;">
                                            You're receiving this email because you have an account with CediBites.<br>
                                            If you have any questions, please contact us at <a href="mailto:support@cedibites.com" style="color: #e49925; text-decoration: none;">support@cedibites.com</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
