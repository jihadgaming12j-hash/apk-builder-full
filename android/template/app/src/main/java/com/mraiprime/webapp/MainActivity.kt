package com.mraiprime.webapp

import android.annotation.SuppressLint
import android.app.Activity
import android.os.Bundle
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import org.json.JSONObject

class MainActivity : Activity() {
    private lateinit var webView: WebView
    private lateinit var swipeRefresh: SwipeRefreshLayout
    private var refreshEnabled = false

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val config = JSONObject(assets.open("app-config.json").bufferedReader().use { it.readText() })
        val mode = config.optString("mode", "url")
        val target = config.optString("target", "")
        refreshEnabled = config.optBoolean("refresh", false)

        webView = WebView(this).apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.loadsImagesAutomatically = true
            settings.allowContentAccess = true
            settings.allowFileAccess = mode == "file"
            settings.allowFileAccessFromFileURLs = false
            settings.allowUniversalAccessFromFileURLs = false
            webChromeClient = WebChromeClient()
            webViewClient = object : WebViewClient() {
                override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
                    super.onPageStarted(view, url, favicon)
                    if (refreshEnabled) swipeRefresh.isRefreshing = true
                }

                override fun onPageFinished(view: WebView?, url: String?) {
                    super.onPageFinished(view, url)
                    swipeRefresh.isRefreshing = false
                }

                override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                    return false
                }
            }
        }

        swipeRefresh = SwipeRefreshLayout(this).apply {
            setColorSchemeColors(android.graphics.Color.rgb(5, 150, 105))
            isEnabled = refreshEnabled
            setOnChildScrollUpCallback { _, _ -> webView.canScrollVertically(-1) }
            setOnRefreshListener { webView.reload() }
            addView(webView)
        }
        setContentView(swipeRefresh)

        val launchTarget = if (mode == "file") "file:///android_asset/site/index.html" else target
        if (launchTarget.isNotBlank()) webView.loadUrl(launchTarget)
    }

    @Deprecated("Deprecated in Android 13; kept for compatibility with API 23+")
    override fun onBackPressed() {
        if (::webView.isInitialized && webView.canGoBack()) {
            webView.goBack()
        } else {
            super.onBackPressed()
        }
    }
}