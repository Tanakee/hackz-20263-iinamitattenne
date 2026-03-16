package com.hackz;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.bind.annotation.CrossOrigin;

@SpringBootApplication
@RestController
@CrossOrigin(origins = "*")
public class GravityApiApplication {

    public static void main(String[] args) {
        SpringApplication.run(GravityApiApplication.class, args);
    }

    @GetMapping("/health")
    public String health() {
        return "Gravity API is running!";
    }

    @PostMapping("/calculate-mass")
    public MassResponse calculateMass(@RequestBody TextRequest request) {
        // テンプレート実装：後で複雑な計算に置き換え
        String text = request.getText();
        double mass = calculateMassLogic(text);
        return new MassResponse(mass, "質量を計算しました");
    }

    private double calculateMassLogic(String text) {
        // 簡易的な質量計算ロジック
        double baseMass = text.length() * 0.1;
        double emotionBonus = text.contains("！") || text.contains("!") ? 50 : 0;
        double lengthBonus = text.length() > 100 ? 20 : 0;
        return baseMass + emotionBonus + lengthBonus;
    }

    // リクエスト・レスポンスクラス
    public static class TextRequest {
        private String text;

        public TextRequest() {}
        public TextRequest(String text) {
            this.text = text;
        }

        public String getText() {
            return text;
        }

        public void setText(String text) {
            this.text = text;
        }
    }

    public static class MassResponse {
        private double mass;
        private String message;

        public MassResponse(double mass, String message) {
            this.mass = mass;
            this.message = message;
        }

        public double getMass() {
            return mass;
        }

        public void setMass(double mass) {
            this.mass = mass;
        }

        public String getMessage() {
            return message;
        }

        public void setMessage(String message) {
            this.message = message;
        }
    }
}
