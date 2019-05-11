# Pay Per View

**INACTIVE NOTICE: This plugin is unsupported by WPMUDEV, we've published it here for those technical types who might want to fork and maintain it for their needs.**

## Translations

Translation files can be found at https://github.com/wpmudev/translations

## Pay Per View lets you sell your digital content with one-time, per-post, pay-period and subscription payment options.

Monetize your content with one of the simplest tools for selling news, articles, tutorials, videos, audio clips, ebooks and anything else you can dream up.  

![Style to fit your theme.](https://premium.wpmudev.org/wp-content/uploads/2012/03/PPV-Video-735x470.jpg)

 Style to fit your theme.

### Link and Go

Pay Per View automatically adjusts to fit your theme, offers a single configuration page for fast setup and can be custom styled for complete integration. Just link your PayPal account and you're ready to start charging users for access. 

### Setup Couldn't be Easier

Sell digital content without having to set up a shopping cart or membership site. Simply install and activate Pay Per View and a shortcode generator will appear in the Visual Editor for your pages and posts.  

![Register and login with popular social media credentials.](https://premium.wpmudev.org/wp-content/uploads/2012/03/PPV-login-buttons-735x470.jpg)

 Register and login with popular social media credentials.

### Use Social Login

Make it easy for users to register by allowing them to signup using their favorite social network credentials. Add one-click signup with Facebook, Twitter, Google and WordPress.com credentials. 

## Usage

### To Get Started

Start by reading [Installing plugins](../wpmu-manual/installing-regular-plugins-on-wpmu/) section in our comprehensive [WordPress and WordPress Multisite Manual](https://premium.wpmudev.org/manuals/) if you are new to WordPress.

### Configuring the Settings

Once installed and activated, you'll see a new menu item in your WordPress Dashboard: **Pay Per View** 

![PayPerView Dash](https://premium.wpmudev.org/wp-content/uploads/2012/03/PayPerView-Dash.png)


 After clicking **‘****Pay Per View**’**** go to the **‘General Settings’** to get your Plugin set up. 

![PPV Settings](https://premium.wpmudev.org/wp-content/uploads/2012/03/PPV-Settings.png)


 6\. Once you’ve completed your General Settings (which should be straight forward), you can move on to create your first post, using the Pay Per View Plugin functionality. When creating a new post or page, there will be a new tool in your editor: The Pay Per View Selection Tool 

![PPV Editor button](https://premium.wpmudev.org/wp-content/uploads/2012/03/PPV-Editor-button.png)


 This button is for adding Pay Per View protection to the current selected content. Upon clicking, there will be a Pay Per View pop up window that will allow you to set a brief description of the selected content, and set your price as well. 

![PPV Insert](https://premium.wpmudev.org/wp-content/uploads/2012/03/PPV-Insert.png)


 Pay Per View also has some unique options in the sidebar when creating a new page or post. 

![1\. Enabled 2\. Method 3\. Excerpt length 4\. Price (USD)](https://premium.wpmudev.org/wp-content/uploads/2012/03/PPV-Sidebar-Settings.png)


 1\. Enabled  
2\. Method  
3\. Excerpt length  
4\. Price (USD)

 **1\. Enabled:** Selects if Pay Per View is enabled for this post or not. If 'Follow global setting' is selected, General Setting page selection will be valid. 'Always enabled' and 'Always disabled' selections will enable or disable Pay Per View for this post, respectively, overriding general setting. **2\. Method:** Selects the content protection method for this post. If 'Follow global setting' is selected, method selected in General Settings page will be applied. If you want to override general settings, select one of the other methods. With 'Use Selection Tool' you need to select each content using the icon on the editor tool bar. For other methods refer to the settings page. **3\. Excerpt length:** If you want to override the number of words that will be used as an excerpt for the unprotected content, enter it here. Please note that this value is only used when Automatic Excerpt method is applied to the post. **4\. Price (USD):** If you want to override the default price to reveal this post/page, enter it here. This value is NOT used when Selection Tool method is applied to the post. There we have it! Click **‘Publish’** and view the final result: **Final result when using the WPMU DEV Network Theme** 

![image](https://premium.wpmudev.org/wp-content/uploads/2012/03/Pay-Per-View-Snagit-14.png)

 **Final result when using the WordPress Twenty Eleven Theme** 

![image](https://premium.wpmudev.org/wp-content/uploads/2012/03/Pay-Per-View-Snagit-13.png)

  

#### Using Pay-per-view Directly In Your Templates

If you want to protect content that is outside of the post content (in a custom field, for example) then you can use the template function wpmudev_ppw_html. This function replaces the HTML with payment buttons, revealing the content only when payment is confirmed. For example, the following code snippet, when added to a theme template, hides a YouTube video until the $1.50 PPV fee has been paid: Note: The content is always protected regardless of the PPV settings for the page or post. As well as protecting the content using the wpmudev_ppw_html function, you also need to ensure that the PPV stylesheet and javascript files are included in the page.  This can be done as follows:
