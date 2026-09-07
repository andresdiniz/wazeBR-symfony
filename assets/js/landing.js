/**
 * wazeBR - Landing Page JavaScript
 * Complete interactive functionality for sales page
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initNavbar();
    initMobileMenu();
    initStatsAnimation();
    initSolutionsTabs();
    initFAQ();
    initPricingToggle();
    initSmoothScroll();
    initScrollAnimations();
});

/**
 * Navbar scroll effect
 */
function initNavbar() {
    const navbar = document.getElementById('navbar');
    if (!navbar) return;
    
    let lastScroll = 0;
    
    window.addEventListener('scroll', () => {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 100) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
        
        lastScroll = currentScroll;
    });
}

/**
 * Mobile menu toggle
 */
function initMobileMenu() {
    const menuBtn = document.getElementById('mobile-menu-btn');
    const navMenu = document.getElementById('nav-menu');
    
    if (!menuBtn || !navMenu) return;
    
    menuBtn.addEventListener('click', () => {
        navMenu.classList.toggle('active');
        menuBtn.classList.toggle('active');
    });
    
    // Close menu on link click
    navMenu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            navMenu.classList.remove('active');
            menuBtn.classList.remove('active');
        });
    });
}

/**
 * Animate stats on scroll
 */
function initStatsAnimation() {
    const statsSection = document.querySelector('.stats-section');
    if (!statsSection) return;
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateStats();
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    
    observer.observe(statsSection);
}

function animateStats() {
    const statNumbers = document.querySelectorAll('.stat-number');
    
    statNumbers.forEach(stat => {
        const finalValue = parseInt(stat.getAttribute('data-value'));
        if (!finalValue) return;
        
        let currentValue = 0;
        const increment = finalValue / 50;
        const duration = 2000;
        const stepTime = duration / 50;
        
        const timer = setInterval(() => {
            currentValue += increment;
            if (currentValue >= finalValue) {
                stat.textContent = formatNumber(finalValue);
                clearInterval(timer);
            } else {
                stat.textContent = formatNumber(Math.floor(currentValue));
            }
        }, stepTime);
    });
}

/**
 * Format numbers with K/M suffix
 */
function formatNumber(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(0) + 'M';
    } else if (num >= 1000) {
        return (num / 1000).toFixed(0) + 'K';
    }
    return num.toString();
}

/**
 * Solutions tabs
 */
function initSolutionsTabs() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.solution-panel');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.getAttribute('data-tab');
            
            // Remove active from all
            tabBtns.forEach(b => b.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));
            
            // Add active to clicked
            btn.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        });
    });
}

/**
 * FAQ accordion
 */
function initFAQ() {
    const faqItems = document.querySelectorAll('.faq-item');
    
    faqItems.forEach(item => {
        const question = item.querySelector('.faq-question');
        
        question.addEventListener('click', () => {
            const isActive = item.classList.contains('active');
            
            // Close all
            faqItems.forEach(i => i.classList.remove('active'));
            
            // Toggle current
            if (!isActive) {
                item.classList.add('active');
            }
        });
    });
}

/**
 * Pricing toggle (monthly/annual)
 */
function initPricingToggle() {
    const pricingSwitch = document.getElementById('pricing-switch');
    if (!pricingSwitch) return;
    
    const prices = document.querySelectorAll('.pricing-price .amount');
    const monthlyPrices = [197, 497, 997];
    const annualPrices = [158, 398, 798]; // 20% discount
    
    pricingSwitch.addEventListener('change', () => {
        const isAnnual = pricingSwitch.checked;
        const pricesArray = isAnnual ? annualPrices : monthlyPrices;
        
        prices.forEach((price, index) => {
            price.textContent = pricesArray[index];
        });
        
        // Update period text
        const periods = document.querySelectorAll('.pricing-price .period');
        periods.forEach(period => {
            period.textContent = isAnnual ? '/mãªªs (anual)' : '/mãªªs';
        });
    });
}

/**
 * Smooth scroll for anchor links
 */
function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                e.preventDefault();
                targetElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
}

/**
 * Scroll animations for elements
 */
function initScrollAnimations() {
    const animatedElements = document.querySelectorAll('.feature-card, .pricing-card, .testimonial-card, .step-card');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });
}

/**
 * Utility: Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Utility: Check if element is in viewport
 */
function isInViewport(element) {
    const rect = element.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        debounce,
        isInViewport,
        formatNumber
    };
}
